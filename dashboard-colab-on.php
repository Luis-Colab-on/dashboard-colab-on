<?php
/*
Plugin Name: Meu Plugin Dashboard Assinantes
Description: Exibe assinaturas Asaas agrupadas por status (Em Dia, Em Atraso e Canceladas) via shortcode [dashboard_assinantes_e_pedidos]
Version: 1.18
Author: Luis Furtado
*/

// Segurança básica
if (!defined('ABSPATH')) { exit; }

/**
 * =============================================================
 * 0) Alias dinâmico de shortcode
 * Aceita [dashboard_assinantes_e_pedidos_3815] e converte para
 * [dashboard_assinantes_e_pedidos id="3815"] antes do do_shortcode.
 * =============================================================
 */
function dash_dynamic_shortcode_alias($content) {
    // Forma autocontida [shortcode_123/]
    $content = preg_replace(
        '/\\[dashboard_assinantes_e_pedidos_(\\d+)(\\s*\\/)?\\]/i',
        '[dashboard_assinantes_e_pedidos id="$1"]',
        $content
    );

    // Forma com fechamento [shortcode_123]...[/shortcode_123]
    $content = preg_replace(
        '/\\[dashboard_assinantes_e_pedidos_(\\d+)\\](.*?)\\[\\/dashboard_assinantes_e_pedidos_\\1\\]/is',
        '[dashboard_assinantes_e_pedidos id="$1"]$2[/dashboard_assinantes_e_pedidos]',
        $content
    );

    // Normaliza atributos SEM "=" para a forma com "="
    $content = preg_replace(
        '/\\[dashboard_assinantes_e_pedidos\\s+(id|corseid)\\s+[\'"]?(\\d+)[\'"]?\\s*\\]/i',
        '[dashboard_assinantes_e_pedidos $1="$2"]',
        $content
    );

    return $content;
}
add_filter('the_content', 'dash_dynamic_shortcode_alias', 9);
add_filter('widget_text', 'dash_dynamic_shortcode_alias', 9);
add_filter('widget_block_content', 'dash_dynamic_shortcode_alias', 9);

/**
 * =============================================================
 * 1) Consultas ao banco
 * =============================================================
 */
function dash_fetch_all_asaas_subscriptions() {
    global $wpdb;
    $subs_table     = $wpdb->prefix . 'processa_pagamentos_asaas_subscriptions';
    $payments_table = $wpdb->prefix . 'processa_pagamentos_asaas';
    $users_table    = $wpdb->users;

    // Pega um único userID "candidato" por orderID (subscriptionID), preferindo o mais recente
    $sql = "
        SELECT
            s.*,
            s.cpf AS cpf,
            COALESCE(u1.display_name, u2.display_name) AS customer_name,
            COALESCE(u1.user_email,   u2.user_email)   AS customer_email,
            COALESCE(u1.ID, u2.ID)    AS resolved_user_id,
            CASE
              WHEN u1.ID IS NOT NULL THEN 'subs'
              WHEN u2.ID IS NOT NULL THEN 'payments'
              ELSE 'none'
            END AS user_source
        FROM {$subs_table} s
        LEFT JOIN (
            SELECT
              t.orderID,
              -- pega o userID do pagamento mais recente para cada orderID
              SUBSTRING_INDEX(GROUP_CONCAT(t.userID ORDER BY t.created DESC), ',', 1) AS alt_user_id
            FROM {$payments_table} t
            WHERE t.type = 'assinatura'
              AND t.userID IS NOT NULL
              AND t.userID <> 0
            GROUP BY t.orderID
        ) p ON p.orderID = s.subscriptionID
        LEFT JOIN {$users_table} u1 ON u1.ID = s.userID
        LEFT JOIN {$users_table} u2 ON u2.ID = p.alt_user_id
        ORDER BY s.id DESC
    ";

    $rows = $wpdb->get_results($sql, ARRAY_A);
    return is_array($rows) ? $rows : [];
}

/**
 * Mapeia product_id para várias assinaturas de uma só vez para reduzir roundtrips.
 * Retorna [ subscription_table_id(int) => product_id(int) ]
 */
function dash_get_product_map_for_subscriptions(array $subscription_ids) {
    global $wpdb;
    $map = [];
    $subscription_ids = array_values(array_filter(array_map('absint', $subscription_ids)));
    if (empty($subscription_ids)) return $map;

    $items_table = $wpdb->prefix . 'processa_pagamentos_asaas_subscriptions_items';
    $in = implode(',', array_fill(0, count($subscription_ids), '%d'));
    $sql = $wpdb->prepare(
        "SELECT subscription_table_id, product_id FROM {$items_table} WHERE subscription_table_id IN ($in)",
        $subscription_ids
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if ($rows) {
        foreach ($rows as $r) {
            $map[(int)$r['subscription_table_id']] = (int)$r['product_id'];
        }
    }
    return $map;
}

/**
 * Busca pagamentos para várias assinaturas de uma vez.
 * Retorna [ subscriptionID(string) => [ rows... ] ],
 * ignorando itens neutros (REFUNDED/CANCELLED/DELETED/CHARGEBACK).
 */
function dash_fetch_payments_map(array $subscriptionIDs) {
    global $wpdb;
    $out = [];

    $subscriptionIDs = array_values(array_filter(array_map('strval', $subscriptionIDs)));
    if (empty($subscriptionIDs)) return $out;

    $payments_table = $wpdb->prefix . 'processa_pagamentos_asaas';

    // Monta placeholders (%s) para IN (...)
    $ph = implode(',', array_fill(0, count($subscriptionIDs), '%s'));
    $sql = $wpdb->prepare(
        "SELECT *
           FROM {$payments_table}
          WHERE type = %s
            AND orderID IN ($ph)
          ORDER BY dueDate ASC, created ASC",
        array_merge(['assinatura'], $subscriptionIDs)
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);
    if ($rows) {
        foreach ($rows as $r) {
            $sid = (string)($r['orderID'] ?? '');
            if ($sid === '') continue;

            $st = strtoupper(trim($r['paymentStatus'] ?? ''));

            // Ignora itens neutros que não devem pesar no cálculo de atraso
            if (in_array($st, ['REFUNDED','CANCELLED','DELETED','CHARGEBACK'], true)) continue;

            if (!isset($out[$sid])) $out[$sid] = [];
            $out[$sid][] = $r;
        }
    }
    return $out;
}

/**
 * =============================================================
 * 2) Helpers de Produto / Ciclos
 * =============================================================
 */
function dash_maybe_get_parent_product_id($product_id) {
    $product_id = (int) $product_id;
    if (!$product_id || !function_exists('wc_get_product')) return $product_id;
    $p = wc_get_product($product_id);
    if ($p && $p->is_type('variation')) {
        $parent_id = (int) $p->get_parent_id();
        return $parent_id ?: $product_id;
    }
    return $product_id;
}

/**
 * Obtém nº de ciclos/meses do produto. Cache estático por request.
 * Retorna: >0 ciclos; 0 sem fim; null desconhecido
 */
function dash_get_subscription_cycles_from_product($product_id) {
    static $cache = [];
    $product_id = (int) $product_id;
    if (!$product_id) return null;
    if (isset($cache[$product_id])) return $cache[$product_id];

    $length  = null;
    $base_id = dash_maybe_get_parent_product_id($product_id);

    // 1) Woo Subscriptions
    if (function_exists('wc_get_product') && class_exists('WC_Subscriptions_Product')) {
        try {
            $product_obj = wc_get_product($base_id);
            if ($product_obj) {
                $len = \WC_Subscriptions_Product::get_length($product_obj);
                if ($len !== null && $len !== '') $length = (int) $len;
            }
        } catch (\Throwable $e) {}
    }

    // 2) Metas comuns
    if ($length === null) {
        $meta_keys = ['_subscription_length','_billing_cycles','subscription_length','assinatura_meses','assinatura_parcelas'];
        foreach ($meta_keys as $k) {
            $v = get_post_meta($base_id, $k, true);
            if ($v !== '' && $v !== null) { $length = (int) $v; break; }
        }
    }

    // 3) Regex em nome/descrição ("6 meses", "12x")
    if ($length === null && function_exists('wc_get_product')) {
        $p = wc_get_product($base_id);
        if ($p) {
            $blob = ' ' . $p->get_name() . ' ';
            if (method_exists($p, 'get_short_description')) $blob .= ' ' . $p->get_short_description();
            if (method_exists($p, 'get_description'))       $blob .= ' ' . $p->get_description();
            if (preg_match('/\\b(\\d{1,2})\\s*mes(?:es)?\\b/i', $blob, $m)) $length = (int) $m[1];
            if ($length === null && preg_match('/\\b(\\d{1,2})\\s*x\\b/i', $blob, $m2)) $length = (int) $m2[1];
        }
    }

    return $cache[$product_id] = $length;
}

/**
 * Infere nº de ciclos a partir de uma WC_Subscription ligada ao order_id.
 */
function dash_get_cycles_from_wc_subscription_order($order_id) {
    if (empty($order_id)) return null;
    if (!function_exists('wcs_get_subscriptions_for_order') || !function_exists('wcs_estimate_periods_between')) return null;

    $subs_wc = wcs_get_subscriptions_for_order($order_id, ['order_type' => 'parent']);
    if (empty($subs_wc) || !is_array($subs_wc)) return null;

    $wc_sub = reset($subs_wc);
    if (!$wc_sub || !is_a($wc_sub, 'WC_Subscription')) return null;

    $end    = (int) $wc_sub->get_time('end');      // 0 => sem fim
    $period =       $wc_sub->get_billing_period(); // 'day','week','month','year'
    if ($end <= 0 || empty($period)) return 0;

    $trial_end   = (int) $wc_sub->get_time('trial_end');
    $length_from = $trial_end > 0 ? $trial_end : (int) $wc_sub->get_time('start');

    if (class_exists('WC_Subscriptions_Synchroniser') &&
        method_exists('WC_Subscriptions_Synchroniser', 'subscription_contains_synced_product') &&
        \WC_Subscriptions_Synchroniser::subscription_contains_synced_product($wc_sub->get_id())) {
        $length_from = (int) $wc_sub->get_time('next_payment');
    }

    $cycles = (int) wcs_estimate_periods_between($length_from, $end, $period);
    return ($cycles >= 0) ? $cycles : null;
}

/**
 * =============================================================
 * 3) Renderização do Dashboard
 * =============================================================
 */
function mostrar_dashboard_assinantes($atts = []) {
    // Agora aceitamos id e corseid
    $atts = shortcode_atts(['id' => 0, 'corseid' => 0], $atts, 'dashboard_assinantes_e_pedidos');

    // prioriza corseid; se não vier, usa id
    $raw = $atts['corseid'] ?: $atts['id'];

    // extrai dígitos se vier em formato estranho (ex.: id "1234")
    if (is_string($raw) && preg_match('/^\\s*(\\d+)/', $raw, $m)) $raw = $m[1];

    $filter_id      = absint($raw);
    $filter_base_id = $filter_id ? dash_maybe_get_parent_product_id($filter_id) : 0;

    $subs = dash_fetch_all_asaas_subscriptions();

    // >>> Usa timezone do WordPress e compara por data (Y-m-d)
    $agora   = current_time('timestamp');
    $hojeYmd = date_i18n('Y-m-d', $agora);

    // Coleta IDs para prefetch
    $subscription_table_ids = [];
    $subscriptionIDs        = [];
    foreach ($subs as $s) {
        $subscription_table_ids[] = (int) ($s['id'] ?? 0);                 // pk da sua tabela
        $subscriptionIDs[]        = (string) ($s['subscriptionID'] ?? ''); // ID textual usado em pagamentos
    }

    // Mapeamentos em lote (menos queries)
    $pid_map = dash_get_product_map_for_subscriptions($subscription_table_ids);
    $pay_map = dash_fetch_payments_map(array_filter($subscriptionIDs));

    // === OPTIONS DO SELECT: IDs únicos de product_id vindos do mapa ===
$course_ids_set = [];
if (!empty($pid_map)) {
    foreach ($pid_map as $__sub_table_id => $__pid) {
        $__pid = (int)$__pid;
        if ($__pid > 0) { $course_ids_set[$__pid] = true; }
    }
}
$course_ids_unique = array_keys($course_ids_set);
sort($course_ids_unique, SORT_NUMERIC);

$course_options = '<option value="">Todos</option>';
foreach ($course_ids_unique as $__cid) {
    $course_options .= '<option value="' . esc_attr($__cid) . '">' . esc_html($__cid) . '</option>';
}


    $expiredStatuses = ['CANCELLED','CANCELED','EXPIRED'];
    $overdueStatuses = ['OVERDUE']; // 'INACTIVE' não implica atraso
    $mapPaid         = ['PAYED','PAID','RECEIVED','RECEIVED_IN_CASH','CONFIRMED'];

    $grupos = ['em_dia' => [], 'em_atraso' => [], 'canceladas' => []];

    foreach ($subs as $sub) {
        $subscription_pk_id = (int) ($sub['id'] ?? 0);
        $subscriptionID     = (string) ($sub['subscriptionID'] ?? '');

        // product_id realmente vinculado a esta assinatura
        $pid_for_select = (int) ($pid_map[$subscription_pk_id] ?? 0);

        // FILTRO PELO ID DO CURSO (via shortcode)
        if ($filter_id) {
            $pid_base = dash_maybe_get_parent_product_id($pid_for_select);
            if ((int)$pid_for_select !== (int)$filter_id && (int)$pid_base !== (int)$filter_base_id) continue;
        }

        $stat_raw = strtoupper(trim($sub['status'] ?? ''));

        // Pagamentos (já filtrados sem itens neutros)
        $payments = array_values($pay_map[$subscriptionID] ?? []);

        // se só tinha itens neutros (ou nenhum válido), e havia registros brutos, some
        if ((isset($pay_map[$subscriptionID]) && empty($payments))) continue;

        if (in_array($stat_raw, $expiredStatuses, true)) {
            $grupos['canceladas'][] = ['sub' => $sub, 'payments' => $payments, 'product_id' => $pid_for_select];
            continue;
        }

        // --- Regra "Em dia na hora" + evitar atraso no dia do vencimento ---
        $hasOverdue = in_array($stat_raw, $overdueStatuses, true); // exemplo: status geral OVERDUE
        if (!$hasOverdue && $payments) {
            foreach ($payments as $p) {
                $p_stat  = strtoupper($p['paymentStatus'] ?? '');
                $due_ts  = strtotime($p['dueDate'] ?? '');
                $orig_ts = strtotime($p['originalDueDate'] ?? '');
                $venc_ts = $due_ts ?: $orig_ts;

                $dueYmd  = $venc_ts ? date_i18n('Y-m-d', $venc_ts) : null;
                $isPaid  = in_array($p_stat, $mapPaid, true);

                // Atraso real: status OVERDUE OU (não pago E dueDate < hoje)
                if (!$isPaid && ($p_stat === 'OVERDUE' || ($dueYmd && $dueYmd < $hojeYmd))) { $hasOverdue = true; break; }
            }
        }

        $key = $hasOverdue ? 'em_atraso' : 'em_dia';
        $grupos[$key][] = ['sub' => $sub, 'payments' => $payments, 'product_id' => $pid_for_select];
    }

    // Contagens para o resumo
    $cnt_dia        = count($grupos['em_dia']);
    $cnt_atraso     = count($grupos['em_atraso']);
    $cnt_canceladas = count($grupos['canceladas']);
    $cnt_total      = $cnt_dia + $cnt_atraso + $cnt_canceladas;

    // Percentuais iniciais (para o 1º paint)
    $pct_total      = $cnt_total > 0 ? 100 : 0;
    $pct_dia        = $cnt_total > 0 ? round(($cnt_dia        / $cnt_total) * 100) : 0;
    $pct_atraso     = $cnt_total > 0 ? round(($cnt_atraso     / $cnt_total) * 100) : 0;
    $pct_canceladas = $cnt_total > 0 ? round(($cnt_canceladas / $cnt_total) * 100) : 0;

    // ===== CSS (mantendo paleta/estética atual) =====
    $css = '<style>
:root{
  --dash-primary:#0a66c2; --dash-primary-dark:#084a8f;
  --dash-text:#1f2937; --dash-muted:#6b7280; --dash-border:#e5e7eb;
  /* Paleta de status */
  --paid:#16a34a; --overdue:#e11d48; --pending:#f59e0b; --future:#e5e7eb; --box-border:rgba(0,0,0,.35);
}
.dash-wrap{font-family:Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji"; color:var(--dash-text)}
.dash-h3{margin:24px 0 8px; font-size:1.125rem; font-weight:700}
.dash-table{width:100%; border-collapse:separate; border-spacing:0; margin:8px 0 24px; font-size:.95rem; box-shadow:0 1px 2px rgba(0,0,0,.04); border:2px solid #000; border-radius:10px; overflow:visible;}
.dash-table thead th{background:var(--dash-primary); color:#fff; padding:10px 8px; text-transform:uppercase; font-weight:600; letter-spacing:.03em}
.dash-table th, .dash-table td{padding:10px 8px; text-align:center; border-bottom:2px solid rgba(0,0,0,0.8); transition: background .15s ease, border-color .15s ease;}
.dash-table td + td, .dash-table th + th{border-left:2px solid rgba(0,0,0,0.8);}
.dash-table tbody tr:nth-child(even){background:#fafafa}
.dash-table tbody tr:hover{background:#e8f2ff}
.dash-table tbody tr:hover td{border-color:var(--dash-primary)}
.dash-table td:nth-child(4){min-width:240px; white-space:nowrap}
.dash-search{display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:4px 0 8px}
.dash-input{flex:1 1 260px; max-width:420px; padding:8px 10px; border:1px solid rgba(0,0,0,0.8); border-radius:8px}
.dash-btn{padding:8px 14px; border-radius:8px; background:var(--dash-primary); color:#fff; border:0; cursor:pointer; transition: transform .14s ease, box-shadow .14s ease, border-color .14s ease, filter .14s ease; border:1px solid var(--dash-primary);}
.dash-btn:hover{background:var(--dash-primary-dark); border:1px solid var(--dash-primary-dark); transform: translateY(-1px) scale(1.1);}
.status-box{position:relative; display:inline-block; width:14px; height:14px; margin:0 2px; border-radius:3px; border:1px solid var(--box-border); transition: transform .14s ease, box-shadow .14s ease, border-color .14s ease, filter .14s ease; cursor: default; z-index:1;}
.status-box:hover{transform: translateY(-1px) scale(1.18); box-shadow: 0 6px 16px rgba(0,0,0,.18); border-color: rgba(0, 0, 0, 0.5);}
.status-box.paid{ background: #16a34a; box-shadow: inset 0 0 0 1px rgba(0,0,0,.06); }
.status-box.paid:hover{ filter: saturate(1.1) brightness(1.03); }
.status-box.overdue{ background:var(--overdue); }
.status-box.pending{
  background:#ff8c00;
  background-image: repeating-linear-gradient(45deg, rgba(255,255,255,.34) 0 2px,rgba(255,255,255,0) 2px 6px);
  background-clip: padding-box;
}
.status-box.future{ background:var(--future); border-style:dashed; }
.status-box[data-tooltip]:hover::after{content:attr(data-tooltip); position:absolute; bottom: calc(100% + 8px); left:50%; transform:translateX(-50%); background:rgba(17,24,39,.96); color:#fff; padding:8px 10px; border-radius:6px; white-space:pre; font-size:.8em; line-height:1.3; max-width:20rem; text-align:left; z-index:9999; pointer-events:none;}
.status-box[data-tooltip]:hover::before{content:""; position:absolute; bottom:100%; left:50%; transform:translateX(-50%); border:6px solid transparent; border-top-color:rgba(17,24,39,.96);}
.debug-info{display:none !important;}
.dash-kpi{border:2px solid #000; border-radius:10px; padding:10px 12px; background:#fafafa; box-shadow:0 1px 2px rgba(0,0,0,.04);}
.dash-kpi .dash-kpi-label{font-size:.82rem; color:#374151; text-transform:uppercase; letter-spacing:.03em}
.dash-kpi .dash-kpi-value{display:flex; flex-direction:column; align-items:flex-start; gap:2px;}
.dash-kpi .dash-kpi-perc{font-weight:900; font-size:1.8rem; line-height:1;}
.dash-kpi .dash-kpi-abs{font-size:.85rem; color:var(--dash-muted);}
.dash-kpi.emdia   .dash-kpi-perc{color:var(--paid);}
.dash-kpi.atraso  .dash-kpi-perc{color:var(--overdue);}
.dash-kpi.cancel  .dash-kpi-perc{color:#111827;}
.dash-summary-note{margin:6px 0 0; font-size:.9rem; color:#374151;}
.dash-summary{display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin:8px 0 10px; align-items:stretch;}
@media (max-width:980px){ .dash-summary{ grid-template-columns:repeat(2,1fr);} }
@media (max-width:560px){ .dash-summary{ grid-template-columns:1fr; } }
.dash-kpi{ display:flex; flex-direction:column; gap:6px; }
.dash-kpi-bar{width:100%; height:8px; background:#eef2f7; border-radius:6px; overflow:hidden; border:1px solid var(--dash-border);}
.dash-kpi-bar > span{ display:block; height:100%; width:0%; transition:width .25s ease; }
.dash-kpi.total  .dash-kpi-bar > span{ background:var(--dash-primary); }
.dash-kpi.emdia  .dash-kpi-bar > span{ background:var(--paid); }
.dash-kpi.atraso .dash-kpi-bar > span{ background:var(--overdue); }
.dash-kpi.cancel .dash-kpi-bar > span{ background:#111827; }
.dash-composition{ margin:2px 0 8px; }
.dash-stacked{display:flex; width:100%; height:10px; border-radius:6px; overflow:hidden; border:1px solid var(--dash-border); background:#eef2f7;}
.dash-stacked .seg{ display:block; height:100%; width:0%; transition:width .25s ease; }
.seg-dia{ background:var(--paid); } .seg-atraso{ background:var(--overdue); } .seg-cancel{ background:#111827; }
.dash-comp-label{ font-size:.8rem; color:var(--dash-muted); margin-top:4px; }
.dash-select{ flex:0 0 180px; max-width:220px; }
.dash-select.dash-input{ padding-right:28px; }
</style>';

    // ===== RESUMO (antes da busca e do dashboard) =====
    $summary = '
<div class="dash-summary" role="region" aria-label="Resumo das assinaturas">
  <div class="dash-kpi total" role="status" aria-live="polite">
    <div class="dash-kpi-label">Assinaturas</div>
    <div class="dash-kpi-value">
      <span class="dash-kpi-perc" id="kpi-total-perc">'.$pct_total.'%</span>
      <small class="dash-kpi-abs" id="kpi-total-abs">'.(int)$cnt_total.' assinaturas</small>
    </div>
    <div class="dash-kpi-bar" aria-hidden="true"><span id="bar-total" style="width:'.$pct_total.'%"></span></div>
  </div>
  <div class="dash-kpi emdia" role="status" aria-live="polite">
    <div class="dash-kpi-label">Em Dia</div>
    <div class="dash-kpi-value">
      <span class="dash-kpi-perc" id="kpi-dia-perc">'.$pct_dia.'%</span>
      <small class="dash-kpi-abs" id="kpi-dia-abs">'.(int)$cnt_dia.' de '.(int)$cnt_total.'</small>
    </div>
    <div class="dash-kpi-bar" aria-hidden="true"><span id="bar-dia" style="width:'.$pct_dia.'%"></span></div>
  </div>
  <div class="dash-kpi atraso" role="status" aria-live="polite">
    <div class="dash-kpi-label">Em Atraso</div>
    <div class="dash-kpi-value">
      <span class="dash-kpi-perc" id="kpi-atraso-perc">'.$pct_atraso.'%</span>
      <small class="dash-kpi-abs" id="kpi-atraso-abs">'.(int)$cnt_atraso.' de '.(int)$cnt_total.'</small>
    </div>
    <div class="dash-kpi-bar" aria-hidden="true"><span id="bar-atraso" style="width:'.$pct_atraso.'%"></span></div>
  </div>
  <div class="dash-kpi cancel" role="status" aria-live="polite">
    <div class="dash-kpi-label">Canceladas</div>
    <div class="dash-kpi-value">
      <span class="dash-kpi-perc" id="kpi-cancel-perc">'.$pct_canceladas.'%</span>
      <small class="dash-kpi-abs" id="kpi-cancel-abs">'.(int)$cnt_canceladas.' de '.(int)$cnt_total.'</small>
    </div>
    <div class="dash-kpi-bar" aria-hidden="true"><span id="bar-cancel" style="width:'.$pct_canceladas.'%"></span></div>
  </div>
</div>
<div class="dash-composition" aria-label="Composição por status (visível)">
  <div class="dash-stacked">
    <span id="seg-dia" class="seg seg-dia" style="width:'.$pct_dia.'%"></span>
    <span id="seg-atraso" class="seg seg-atraso" style="width:'.$pct_atraso.'%"></span>
    <span id="seg-cancel" class="seg seg-cancel" style="width:'.$pct_canceladas.'%"></span>
  </div>
  <div class="dash-comp-label">Composição do conjunto visível (Em Dia / Em Atraso / Canceladas)</div>
</div>
<div class="dash-summary-note" id="dash-summary-note">Mostrando '.(int)$cnt_total.' de '.(int)$cnt_total.' assinaturas</div>';

    $search = '<div class="dash-wrap">'.$summary.'<div class="dash-search" role="search" aria-label="Filtrar assinantes">
        <select id="dash-course-filter" class="dash-input dash-select" aria-label="Filtrar por ID do curso">'
        . $course_options .
        '</select>
        <input type="text" id="dash-search-input" class="dash-input" placeholder="Buscar por nome, e-mail ou CPF" aria-label="Buscar por nome, e-mail ou CPF">
        <button type="button" id="dash-search-btn" class="dash-btn">Buscar</button>
    </div>';


    // ===== Legenda visual =====
    $legend = '
      <div style="display:flex;gap:14px;align-items:center;margin:4px 0 10px;flex-wrap:wrap;font-size:.9rem;color:#374151">
        <span><span class="status-box paid"></span> Pago</span>
        <span><span class="status-box overdue"></span> Em atraso</span>
        <span><span class="status-box pending"></span> Pendente</span>
        <span><span class="status-box future"></span> Não criada</span>
      </div>';

    // ===== Tabela builder =====
    $build_row = function(array $sub, array $payments, int $product_id) use ($agora, $hojeYmd) {
        $subscription_pk_id = (int) ($sub['id'] ?? 0);
        $base_id            = dash_maybe_get_parent_product_id($product_id);

        // Total de parcelas (estimado)
        $totalInstallments = 6; // fallback
        $cycles = null;
        if ($product_id) {
            $cycles = dash_get_subscription_cycles_from_product($product_id);
            if ($cycles !== null) $totalInstallments = ($cycles > 0) ? (int) $cycles : max(count($payments), 6);
        }
        if ((!$product_id || $cycles === null) && !empty($sub['order_id'])) {
            $maybe_cycles = dash_get_cycles_from_wc_subscription_order($sub['order_id']);
            if ($maybe_cycles !== null) $totalInstallments = ($maybe_cycles > 0) ? (int) $maybe_cycles : max(count($payments), 6);
        }
        if (count($payments) > $totalInstallments) $totalInstallments = count($payments);

        // Mapeia parcelas às posições
        $installments = array_fill(0, $totalInstallments, null);
        foreach ($payments as $p) {
            $idx = (isset($p['installmentNumber']) && is_numeric($p['installmentNumber'])) ? ((int)$p['installmentNumber'] - 1) : null;
            if ($idx === null || $idx < 0 || $idx >= $totalInstallments) $idx = array_search(null, $installments, true);
            if ($idx !== false) $installments[$idx] = $p;
        }

        // Render row
        $cpf_display = $sub['cpf'] ?? ($sub['customer_cpf'] ?? '—');
        $r  = '<tr id="sub-' . (int) $subscription_pk_id . '" ' .
              'data-course-id="' . esc_attr($product_id ?: 0) . '" ' .
              'data-course-base-id="' . esc_attr($base_id ?: 0) . '">';

        $r .= '<td>' . esc_html($sub['customer_name'] ?? '—') . '</td>';
        $r .= '<td>' . esc_html($sub['customer_email'] ?? '—') . '</td>';
        $r .= '<td>' . esc_html($cpf_display) . '</td>';

        $r .= '<td>';
        foreach ($installments as $p) {
            // defaults: slot ainda não criado
            $cls = 'future'; $pag = '—'; $status_tip = 'Não criada'; $tooltip = 'Parcela não criada';

            if ($p) {
                $val     = isset($p['value']) ? number_format((float)$p['value'], 2, ',', '.') : '0,00';
                $creAt   = $p['created'] ?? ($p['createdAt'] ?? '');
                $created = $creAt ? date_i18n('d/m/Y - H:i', strtotime($creAt)) : '—';

                $p_stat  = strtoupper($p['paymentStatus'] ?? '');
                $due_ts  = strtotime($p['dueDate'] ?? '');
                $orig_ts = strtotime($p['originalDueDate'] ?? '');
                $venc_ts = $due_ts ?: $orig_ts;
                $venc    = $venc_ts ? date_i18n('d/m/Y', $venc_ts) : '—';

                $dueYmd  = $venc_ts ? date_i18n('Y-m-d', $venc_ts) : null;
                $isPaid  = in_array($p_stat, ['PAYED','PAID','RECEIVED','RECEIVED_IN_CASH','CONFIRMED'], true);
                $isOverdue = (!$isPaid && ($p_stat === 'OVERDUE' || ($dueYmd && $dueYmd < $hojeYmd)));

                if ($isPaid) {
                    $cls = 'paid'; $pag = date_i18n('d/m/Y', strtotime($p['paymentDate'] ?? '')); $status_tip = 'Pago';
                } elseif ($isOverdue) {
                    $cls = 'overdue'; $pag = 'ATRASADA'; $status_tip = 'Em atraso';
                } else {
                    $cls = 'pending'; $status_tip = 'Pendente';
                }

                // tooltip completo quando existe parcela
                $tooltip = "Status: {$status_tip}\nValor: R$ {$val}\nCriação: {$created}\nVencimento: {$venc}\nPagamento: {$pag}";
            }

            // imprime uma única box por parcela
            $r .= '<span class="status-box ' . esc_attr($cls) . '" data-tooltip="' . esc_attr($tooltip) . '"></span>';
        }
        $r .= '</td>';

        // Status geral da assinatura
        $stat = strtoupper(trim($sub['status'] ?? ''));
        $r .= '<td>';
        if (in_array($stat, ['CANCELLED','CANCELED','EXPIRED'], true)) {
            $date = $sub['cancelled_at'] ?? ($sub['cancelledAt'] ?? ($sub['canceledAt'] ?? ($sub['expiredAt'] ?? ($sub['updated'] ?? ''))));
            $r   .= $date ? 'Cancelado em ' . esc_html(date_i18n('d/m/Y', strtotime($date))) : 'Cancelado';
        } else {
            $r .= ($stat === 'ACTIVE') ? 'Ativo' : (($stat === 'INACTIVE') ? 'Inativo' : esc_html(ucfirst(strtolower($sub['status'] ?? '—'))));
        }
        $r .= '</td></tr>';

        return $r;
    };

    $html = $css . $search . $legend;

    foreach (['em_atraso' => 'Em Atraso', 'em_dia' => 'Em Dia', 'canceladas' => 'Canceladas'] as $key => $titulo) {
        $html .= '<h3 class="dash-h3">Assinaturas — ' . esc_html($titulo) . '</h3>';
        // >>> adiciona id para contagem por grupo no resumo
        $html .= "<table id='dash-table-{$key}' class='dash-table' role='table' aria-label='Assinaturas {$titulo}'><thead><tr><th>Nome</th><th>E-mail</th><th>CPF</th><th>Pagamentos</th><th>Status</th></tr></thead><tbody>";
        if (empty($grupos[$key])) {
            $html .= "<tr><td colspan='5'><em>Nenhuma assinatura nesta categoria.</em></td></tr>";
        } else {
            foreach ($grupos[$key] as $entry) {
                $html .= $build_row($entry['sub'], $entry['payments'], (int)$entry['product_id']);
            }
        }
        $html .= '</tbody></table>';
    }

    // ===== JS: filtro em tempo real + atualização do resumo =====
$html .= "<script>
(function(){
  var $ = function(id){ return document.getElementById(id); };

  function normalize(s){
    return (s||'').normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').toLowerCase();
  }

  function countVisibleRows(tableId){
    var t = $(tableId); if(!t) return 0;
    var rows = t.querySelectorAll('tbody tr[id]');
    var n = 0;
    rows.forEach(function(r){
      var rid = r.id || '';
      if(rid.slice(0,4) === 'sub-' && r.style.display !== 'none'){ n++; }
    });
    return n;
  }

  function setText(id, txt){ var el = $(id); if(el){ el.textContent = txt; } }
  function setBar(id, pct){
    var el = $(id);
    if(el){ el.style.width = Math.max(0, Math.min(100, pct)) + '%'; }
  }
  function pct(part, total){ return total ? Math.round((part/total)*100) : 0; }

  function updateSummary(){
    var visDia   = countVisibleRows('dash-table-em_dia');
    var visAtr   = countVisibleRows('dash-table-em_atraso');
    var visCanc  = countVisibleRows('dash-table-canceladas');
    var visTotal = visDia + visAtr + visCanc;

    var pDia = pct(visDia,  visTotal);
    var pAtr = pct(visAtr,  visTotal);
    var pCan = pct(visCanc, visTotal);

    setText('kpi-total-perc',  (visTotal>0 ? '100%' : '0%'));
    setText('kpi-dia-perc',    pDia + '%');
    setText('kpi-atraso-perc', pAtr + '%');
    setText('kpi-cancel-perc', pCan + '%');

    setText('kpi-total-abs',   visTotal + ' assinaturas');
    setText('kpi-dia-abs',     visDia   + ' de ' + visTotal);
    setText('kpi-atraso-abs',  visAtr   + ' de ' + visTotal);
    setText('kpi-cancel-abs',  visCanc  + ' de ' + visTotal);

    setBar('bar-total',  visTotal>0 ? 100 : 0);
    setBar('bar-dia',    pDia);
    setBar('bar-atraso', pAtr);
    setBar('bar-cancel', pCan);

    setBar('seg-dia',    pDia);
    setBar('seg-atraso', pAtr);
    setBar('seg-cancel', pCan);
  }

  function applyFilter(){
    var inp = $('dash-search-input');
    var sel = $('dash-course-filter');
    var q = normalize(inp ? inp.value.trim() : '');
    var selectedId = sel ? String(sel.value || '').toLowerCase() : '';

    document.querySelectorAll('.dash-table tbody tr[id]').forEach(function(tr){
      var c = tr.cells; if(!c || c.length < 3){ tr.style.display = ''; return; }

      var nome  = normalize(c[0].textContent);
      var email = normalize(c[1].textContent);
      var cpf   = normalize(c[2].textContent);

      var rid   = (tr.id||'').toLowerCase();
      var cid   = String(tr.getAttribute('data-course-id')||'').toLowerCase();
      var cbase = String(tr.getAttribute('data-course-base-id')||'').toLowerCase();

      var matchText = (
        q === '' ||
        nome.indexOf(q)  > -1 ||
        email.indexOf(q) > -1 ||
        cpf.indexOf(q)   > -1 ||
        rid.indexOf(q)   > -1 ||
        cid.indexOf(q)   > -1 ||
        cbase.indexOf(q) > -1
      );

      var matchId = (selectedId === '' || cid === selectedId || cbase === selectedId);

      tr.style.display = (matchText && matchId) ? '' : 'none';
    });

    updateSummary();
  }

  var btn = $('dash-search-btn');
  if(btn){ btn.addEventListener('click', applyFilter); }

  var inp = $('dash-search-input');
  if(inp){
    inp.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ applyFilter(); } });
    inp.addEventListener('input', applyFilter);
  }

  var sel = $('dash-course-filter');
  if(sel){ sel.addEventListener('change', applyFilter); }

  // 1º paint (sem filtro) — KPIs corretos
  updateSummary();
})();
</script>
</div>";


    return $html;
}
add_shortcode('dashboard_assinantes_e_pedidos', 'mostrar_dashboard_assinantes');
