<?php
/*
Plugin Name: Meu Plugin Dashboard Assinantes
Description: Exibe assinaturas Asaas agrupadas por status (Em Dia, Em Atraso e Canceladas) via shortcode [dashboard_assinantes_e_pedidos]
Version: 1.19
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
    $content = preg_replace('/\[dashboard_assinantes_e_pedidos_(\d+)(\s*\/)?\]/i','[dashboard_assinantes_e_pedidos id="$1"]',$content);
    // Forma com fechamento [shortcode_123]...[/shortcode_123]
    $content = preg_replace('/\[dashboard_assinantes_e_pedidos_(\d+)\](.*?)\[\/dashboard_assinantes_e_pedidos_\1\]/is','[dashboard_assinantes_e_pedidos id="$1"]$2[/dashboard_assinantes_e_pedidos]',$content);
    // Normaliza atributos SEM "=" para a forma com "="
    $content = preg_replace('/\[dashboard_assinantes_e_pedidos\s+(id|corseid)\s+[\'"]?(\d+)[\'"]?\s*\]/i','[dashboard_assinantes_e_pedidos $1="$2"]',$content);
    return $content;
}
add_filter('the_content','dash_dynamic_shortcode_alias',9);
add_filter('widget_text','dash_dynamic_shortcode_alias',9);
add_filter('widget_block_content','dash_dynamic_shortcode_alias',9);

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

    // E-mails a ignorar
    $IGNORED_EMAILS = array_map('strtolower', [
        'mwry5467@gmail.com',
        'financeiro4@colab-on.com.br',
        'financeiro@colab-on.com.br',
        'jessica@colab-on.com.br',
        'david.forli@colab-on.com.br',
    ]);

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
    if (!is_array($rows)) {
        return [];
    }

    // Filtro pós-consulta: remove linhas com e-mails indesejados
    $rows = array_values(array_filter($rows, function($r) use ($IGNORED_EMAILS) {
        $email = strtolower(trim($r['customer_email'] ?? ''));
        if ($email === '') {
            // Sem e-mail resolvido: mantém
            return true;
        }
        return !in_array($email, $IGNORED_EMAILS, true);
    }));

    return $rows;
}

/**
 * Mapeia product_id para várias assinaturas de uma só vez para reduzir roundtrips.
 * Retorna [ subscription_table_id(int) => product_id(int) ]
 */
function dash_get_product_map_for_subscriptions(array $subscription_ids) {
    global $wpdb;
    $map = [];
    $subscription_ids = array_values(array_filter(array_map('absint',$subscription_ids)));
    if (empty($subscription_ids)) return $map;

    $items_table = $wpdb->prefix . 'processa_pagamentos_asaas_subscriptions_items';

    // CHUNKING para listas grandes
    $chunkSize = 1000;
    $chunks = array_chunk($subscription_ids, $chunkSize);
    foreach ($chunks as $chunk) {
        $in  = implode(',', array_fill(0, count($chunk), '%d'));
        $sql = $wpdb->prepare("SELECT subscription_table_id, product_id FROM {$items_table} WHERE subscription_table_id IN ($in)", $chunk);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($rows) {
            foreach ($rows as $r) { $map[(int)$r['subscription_table_id']] = (int)$r['product_id']; }
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
    $subscriptionIDs = array_values(array_filter(array_map('strval',$subscriptionIDs)));
    if (empty($subscriptionIDs)) return $out;

    $payments_table = $wpdb->prefix . 'processa_pagamentos_asaas';

    // CHUNKING para evitar IN muito grande
    $chunkSize = 1000;
    $chunks = array_chunk($subscriptionIDs, $chunkSize);
    foreach ($chunks as $chunk) {
        $ph  = implode(',', array_fill(0, count($chunk), '%s'));
        $sql = $wpdb->prepare(
            "SELECT *
             FROM {$payments_table}
             WHERE type = %s
               AND orderID IN ($ph)
               AND paymentStatus NOT IN ('REFUNDED','CANCELLED','DELETED','CHARGEBACK')
             ORDER BY dueDate ASC, created ASC",
            array_merge(['assinatura'], $chunk)
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($rows) {
            foreach ($rows as $r) {
                $sid = (string)($r['orderID'] ?? '');
                if ($sid === '') continue;
                if (!isset($out[$sid])) $out[$sid] = [];
                $out[$sid][] = $r;
            }
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
    static $cache = [];
    $product_id = (int)$product_id;
    if (!$product_id) return $product_id;
    if (isset($cache[$product_id])) return $cache[$product_id];

    if (!function_exists('wc_get_product')) return $cache[$product_id] = $product_id;
    $p = wc_get_product($product_id);
    if ($p && $p->is_type('variation')) {
        $parent_id = (int)$p->get_parent_id();
        return $cache[$product_id] = ($parent_id ?: $product_id);
    }
    return $cache[$product_id] = $product_id;
}

/**
 * Obtém nº de ciclos/meses do produto. Cache estático por request.
 * Retorna: >0 ciclos; 0 sem fim; null desconhecido
 */
function dash_get_subscription_cycles_from_product($product_id) {
    static $cache = [];
    $product_id = (int)$product_id;
    if (!$product_id) return null;
    if (isset($cache[$product_id])) return $cache[$product_id];

    $length = null;
    $base_id = dash_maybe_get_parent_product_id($product_id);

    if (function_exists('wc_get_product') && class_exists('WC_Subscriptions_Product')) {
        try {
            $product_obj = wc_get_product($base_id);
            if ($product_obj) {
                $len = \WC_Subscriptions_Product::get_length($product_obj);
                if ($len !== null && $len !== '') $length = (int)$len;
            }
        } catch (\Throwable $e) {}
    }

    if ($length === null) {
        $meta_keys = ['_subscription_length','_billing_cycles','subscription_length','assinatura_meses','assinatura_parcelas'];
        foreach ($meta_keys as $k) {
            $v = get_post_meta($base_id,$k,true);
            if ($v !== '' && $v !== null) { $length = (int)$v; break; }
        }
    }

    if ($length === null && function_exists('wc_get_product')) {
        $p = wc_get_product($base_id);
        if ($p) {
            $blob = ' '.$p->get_name().' ';
            if (method_exists($p,'get_short_description')) $blob .= ' '.$p->get_short_description();
            if (method_exists($p,'get_description'))       $blob .= ' '.$p->get_description();
            if (preg_match('/\b(\d{1,2})\s*mes(?:es)?\b/i',$blob,$m)) $length = (int)$m[1];
            if ($length === null && preg_match('/\b(\d{1,2})\s*x\b/i',$blob,$m2)) $length = (int)$m2[1];
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

    $subs_wc = wcs_get_subscriptions_for_order($order_id, ['order_type'=>'parent']);
    if (empty($subs_wc) || !is_array($subs_wc)) return null;

    $wc_sub = reset($subs_wc);
    if (!$wc_sub || !is_a($wc_sub,'WC_Subscription')) return null;

    $end = (int)$wc_sub->get_time('end');
    $period = $wc_sub->get_billing_period();
    if ($end <= 0 || empty($period)) return 0;

    $trial_end = (int)$wc_sub->get_time('trial_end');
    $length_from = $trial_end > 0 ? $trial_end : (int)$wc_sub->get_time('start');

    if (class_exists('WC_Subscriptions_Synchroniser') &&
        method_exists('WC_Subscriptions_Synchroniser','subscription_contains_synced_product') &&
        \WC_Subscriptions_Synchroniser::subscription_contains_synced_product($wc_sub->get_id())) {
        $length_from = (int)$wc_sub->get_time('next_payment');
    }

    $cycles = (int) wcs_estimate_periods_between($length_from,$end,$period);
    return ($cycles >= 0) ? $cycles : null;
}

/**
 * =============================================================
 * 3) Renderização do Dashboard
 * =============================================================
 */
function mostrar_dashboard_assinantes($atts = []) {
    $atts = shortcode_atts(['id'=>0,'corseid'=>0], $atts, 'dashboard_assinantes_e_pedidos');
    $raw = $atts['corseid'] ?: $atts['id'];
    if (is_string($raw) && preg_match('/^\s*(\d+)/',$raw,$m)) $raw = $m[1];

    $filter_id = absint($raw);
    $filter_base_id = $filter_id ? dash_maybe_get_parent_product_id($filter_id) : 0;

    $subs = dash_fetch_all_asaas_subscriptions();
    $agora = current_time('timestamp');
    $hojeYmd = date_i18n('Y-m-d',$agora);

    $subscription_table_ids = [];
    $subscriptionIDs = [];
    foreach ($subs as $s) {
        $subscription_table_ids[] = (int)($s['id'] ?? 0);
        $subscriptionIDs[] = (string)($s['subscriptionID'] ?? '');
    }

    $pid_map = dash_get_product_map_for_subscriptions($subscription_table_ids);
    $pay_map = dash_fetch_payments_map(array_filter($subscriptionIDs));

    // Lista única de IDs de cursos para o <select>
    $course_ids_set = [];
    if (!empty($pid_map)) {
        foreach ($pid_map as $__sub_table_id => $__pid) { $__pid = (int)$__pid; if ($__pid > 0) { $course_ids_set[$__pid] = true; } }
    }
    $course_ids_unique = array_keys($course_ids_set);
    sort($course_ids_unique, SORT_NUMERIC);

    $name_cache = [];
    $course_options = '<option value="">Todos</option>';
    foreach ($course_ids_unique as $__cid) {
        $display_id = dash_maybe_get_parent_product_id($__cid);
        if (!isset($name_cache[$display_id])) {
            $name = '';
            if (function_exists('wc_get_product')) { $p = wc_get_product($display_id); if ($p) { $name = $p->get_name(); } }
            if (!$name) { $name = get_post_field('post_title',$display_id); }
            if (!$name) { $name = 'Produto #'. $__cid; }
            $name_cache[$display_id] = $name;
        }
        $course_options .= '<option value="'.esc_attr($__cid).'">'.esc_html($name_cache[$display_id]).'</option>';
    }

    $expiredStatuses = ['CANCELLED','CANCELED','EXPIRED'];
    $overdueStatuses = ['OVERDUE'];
    $mapPaid = ['PAYED','PAID','RECEIVED','RECEIVED_IN_CASH','CONFIRMED'];

    $grupos = ['em_dia'=>[],'em_atraso'=>[],'canceladas'=>[]];

    foreach ($subs as $sub) {
        $subscription_pk_id = (int)($sub['id'] ?? 0);
        $subscriptionID = (string)($sub['subscriptionID'] ?? '');
        $pid_for_select = (int)($pid_map[$subscription_pk_id] ?? 0);

        if ($filter_id) {
            $pid_base = dash_maybe_get_parent_product_id($pid_for_select);
            if ((int)$pid_for_select !== (int)$filter_id && (int)$pid_base !== (int)$filter_base_id) continue;
        }

        $stat_raw = strtoupper(trim($sub['status'] ?? ''));
        $payments = array_values($pay_map[$subscriptionID] ?? []);
        if ((isset($pay_map[$subscriptionID]) && empty($payments))) continue;

        if (in_array($stat_raw,$expiredStatuses,true)) {
            $grupos['canceladas'][] = ['sub'=>$sub,'payments'=>$payments,'product_id'=>$pid_for_select];
            continue;
        }

        $hasOverdue = in_array($stat_raw,$overdueStatuses,true);
        if (!$hasOverdue && $payments) {
            foreach ($payments as $p) {
                $p_stat = strtoupper($p['paymentStatus'] ?? '');
                $due_ts  = strtotime($p['dueDate'] ?? '');
                $orig_ts = strtotime($p['originalDueDate'] ?? '');
                $venc_ts = $due_ts ?: $orig_ts;
                $dueYmd = $venc_ts ? date_i18n('Y-m-d',$venc_ts) : null;
                $isPaid = in_array($p_stat,$mapPaid,true);
                if (!$isPaid && ($p_stat === 'OVERDUE' || ($dueYmd && $dueYmd < $hojeYmd))) { $hasOverdue = true; break; }
            }
        }

        $key = $hasOverdue ? 'em_atraso' : 'em_dia';
        $grupos[$key][] = ['sub'=>$sub,'payments'=>$payments,'product_id'=>$pid_for_select];
    }

    $cnt_dia = count($grupos['em_dia']);
    $cnt_atraso = count($grupos['em_atraso']);
    $cnt_canceladas = count($grupos['canceladas']);
    $cnt_total = $cnt_dia + $cnt_atraso + $cnt_canceladas;

    $pct_total = $cnt_total > 0 ? 100 : 0;
    $pct_dia = $cnt_total > 0 ? round(($cnt_dia / $cnt_total) * 100) : 0;
    $pct_atraso = $cnt_total > 0 ? round(($cnt_atraso / $cnt_total) * 100) : 0;
    $pct_canceladas = $cnt_total > 0 ? round(($cnt_canceladas / $cnt_total) * 100) : 0;

// ===== CSS =====
$css = '<style>
:root{--dash-primary:#0a66c2;--dash-primary-dark:#084a8f;--dash-text:#1f2937;--dash-muted:#6b7280;--dash-border:#e5e7eb;--paid:#16a34a;--overdue:#e11d48;--pending:#f59e0b;--future:#e5e7eb;--box-border:rgba(0,0,0,.35);}
.dash-wrap{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,"Apple Color Emoji","Segoe UI Emoji";color:var(--dash-text);}
.dash-h3{margin:24px 0 8px;font-size:1.125rem;font-weight:700;}
.dash-table{width:100%;border-collapse:separate;border-spacing:0;margin:8px 0 24px;font-size:.95rem;box-shadow:0 1px 2px rgba(0,0,0,.04);border:2px solid #000;border-radius:10px;overflow:visible;}
.dash-table thead th{background:var(--dash-primary);color:#fff;padding:10px 8px;text-transform:uppercase;font-weight:600;letter-spacing:.03em;}
.dash-table th,.dash-table td{padding:10px 8px;text-align:center;border-bottom:2px solid rgba(0,0,0,0.8);transition:background .15s ease,border-color .15s ease;}
.dash-table td + td,.dash-table th + th{border-left:2px solid rgba(0,0,0,0.8);}
.dash-table tbody tr:nth-child(even){background:#fafafa;}
.dash-table tbody tr:hover{background:#e8f2ff;}
.dash-table tbody tr:hover td{border-color:var(--dash-primary);}
.dash-table td:nth-child(4){min-width:240px;white-space:nowrap;}
.dash-search{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:4px 0 8px;}
.dash-input{flex:1 1 260px;max-width:420px;padding:8px 10px;border:1px solid rgba(0,0,0,0.8);border-radius:8px;}
.dash-btn{padding:8px 14px;border-radius:8px;background:var(--dash-primary);color:#fff;border:0;cursor:pointer;transition:transform .14s ease,box-shadow .14s ease,border-color .14s ease,filter .14s ease;border:1px solid var(--dash-primary);}
.dash-btn:hover{background:var(--dash-primary-dark);border:1px solid var(--dash-primary-dark);transform:translateY(-1px) scale(1.1);}
.status-box{position:relative;display:inline-block;width:14px;height:14px;margin:0 2px;border-radius:3px;border:1px solid var(--box-border);transition:transform .14s ease,box-shadow .14s ease,border-color .14s ease,filter .14s ease;cursor:default;z-index:1;}
.status-box:hover,.status-box:focus-visible{transform:translateY(-1px) scale(1.18);box-shadow:0 6px 16px rgba(0,0,0,.18);border-color:rgba(0,0,0,0.5);outline:none;}
.status-box.paid{background:#16a34a;box-shadow:inset 0 0 0 1px rgba(0,0,0,.06);}
.status-box.paid:hover,.status-box.paid:focus-visible{filter:saturate(1.1) brightness(1.03);}
.status-box.overdue{background:var(--overdue);}
.status-box.pending{background:#ff8c00;background-image:repeating-linear-gradient(45deg,rgba(255,255,255,.34) 0 2px,rgba(255,255,255,0) 2px 6px);background-clip:padding-box;}
.status-box.future{background:var(--future);border-style:dashed;}
.status-box[data-tooltip]:hover::after,.status-box[data-tooltip]:focus-visible::after{content:attr(data-tooltip);position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);background:rgba(17,24,39,.96);color:#fff;padding:8px 10px;border-radius:6px;white-space:pre;font-size:.8em;line-height:1.3;max-width:20rem;text-align:left;z-index:9999;pointer-events:none;}
.status-box[data-tooltip]:hover::before,.status-box[data-tooltip]:focus-visible::before{content:"";position:absolute;bottom:100%;left:50%;transform:translateX(-50%);border:6px solid transparent;border-top-color:rgba(17,24,39,.96);}
.dash-table tbody tr[id]{position:relative;z-index:1;}
.dash-table tbody tr[data-tooltip]:hover::after{content:attr(data-tooltip);position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);background:rgba(17,24,39,.96);color:#fff;padding:8px 10px;border-radius:6px;box-sizing:border-box;white-space:pre-wrap;overflow-wrap:anywhere;word-break:normal;max-width:min(26rem, calc(100% - 24px));text-align:left;font-size:.8em;line-height:1.3;z-index:9999;pointer-events:none;}
.debug-info{display:none !important;}
.dash-kpi{border:2px solid #000;border-radius:10px;padding:10px 12px;background:#fafafa;box-shadow:0 1px 2px rgba(0,0,0,.04);}
.dash-kpi .dash-kpi-label{font-size:.82rem;color:#374151;text-transform:uppercase;letter-spacing:.03em;}
.dash-kpi .dash-kpi-value{display:flex;flex-direction:column;align-items:flex-start;gap:2px;}
.dash-kpi .dash-kpi-perc{font-weight:900;font-size:1.8rem;line-height:1;}
.dash-kpi .dash-kpi-abs{font-size:.85rem;color:var(--dash-muted);}
.dash-kpi.emdia .dash-kpi-perc{color:var(--paid);}
.dash-kpi.atraso .dash-kpi-perc{color:var(--overdue);}
.dash-kpi.cancel .dash-kpi-perc{color:#111827;}
.dash-summary-note{margin:6px 0 0;font-size:.9rem;color:#374151;}
.dash-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:8px 0 10px;align-items:stretch;}
@media (max-width:980px){.dash-summary{grid-template-columns:repeat(2,1fr);}}
@media (max-width:560px){.dash-summary{grid-template-columns:1fr;}}
.dash-kpi{display:flex;flex-direction:column;gap:6px;}
.dash-kpi-bar{width:100%;height:8px;background:#eef2f7;border-radius:6px;overflow:hidden;border:1px solid var(--dash-border);}
.dash-kpi-bar > span{display:block;height:100%;width:0%;transition:width .25s ease;}
.dash-kpi.total .dash-kpi-bar > span{background:var(--dash-primary);}
.dash-kpi.emdia .dash-kpi-bar > span{background:var(--paid);}
.dash-kpi.atraso .dash-kpi-bar > span{background:var(--overdue);}
.dash-kpi.cancel .dash-kpi-bar > span{background:#111827;}
.dash-composition{margin:2px 0 8px;}
.dash-stacked{display:flex;width:100%;height:10px;border-radius:6px;overflow:hidden;border:1px solid var(--dash-border);background:#eef2f7;}
.dash-stacked .seg{display:block;height:100%;width:0%;transition:width .25s ease;}
.seg-dia{background:var(--paid);}
.seg-atraso{background:var(--overdue);}
.seg-cancel{background:#111827;}
.dash-comp-label{font-size:.8rem;color:var(--dash-muted);margin-top:4px;}
.dash-select{flex:0 0 180px;max-width:220px;}
.dash-select.dash-input{padding-right:28px;}
/* Banner do modo histórico */
.dash-hist-banner{margin:6px 0 10px;padding:8px 12px;border:1px dashed #6b7280;border-radius:8px;background:#fffbea;color:#374151;font-size:.92rem}
.dash-hist-row{display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0 0 8px;}
.dash-hist-row .dash-input{ flex:0 0 190px; max-width:220px; } 
</style>';

$css_extra = '<style>
/* Esconde parcelas fora do mês escolhido e/ou a linha inteira quando aplicável */
.month-dim-hide { display:none !important; }
.month-filter-hide { display:none !important; }
/* Datepicker em modo mês/ano (esconde os dias) */
.ui-datepicker .ui-datepicker-calendar { display:none; }
.ui-datepicker .ui-datepicker-current { display:none; }
.ui-state-disabled { opacity:.45; pointer-events:none; }
</style>';

$css .= $css_extra;

    // ===== RESUMO =====
    $summary = '<div class="dash-summary" role="region" aria-label="Resumo das assinaturas"><div class="dash-kpi total" role="status" aria-live="polite"><div class="dash-kpi-label">Assinaturas</div><div class="dash-kpi-value"><span class="dash-kpi-perc" id="kpi-total-perc">'.$pct_total.'%</span><small class="dash-kpi-abs" id="kpi-total-abs">'.(int)$cnt_total.' assinaturas</small></div><div class="dash-kpi-bar" aria-hidden="true"><span id="bar-total" style="width:'.$pct_total.'%"></span></div></div><div class="dash-kpi emdia" role="status" aria-live="polite"><div class="dash-kpi-label">Em Dia</div><div class="dash-kpi-value"><span class="dash-kpi-perc" id="kpi-dia-perc">'.$pct_dia.'%</span><small class="dash-kpi-abs" id="kpi-dia-abs">'.(int)$cnt_dia.' de '.(int)$cnt_total.'</small></div><div class="dash-kpi-bar" aria-hidden="true"><span id="bar-dia" style="width:'.$pct_dia.'%"></span></div></div><div class="dash-kpi atraso" role="status" aria-live="polite"><div class="dash-kpi-label">Em Atraso</div><div class="dash-kpi-value"><span class="dash-kpi-perc" id="kpi-atraso-perc">'.$pct_atraso.'%</span><small class="dash-kpi-abs" id="kpi-atraso-abs">'.(int)$cnt_atraso.' de '.(int)$cnt_total.'</small></div><div class="dash-kpi-bar" aria-hidden="true"><span id="bar-atraso" style="width:'.$pct_atraso.'%"></span></div></div><div class="dash-kpi cancel" role="status" aria-live="polite"><div class="dash-kpi-label">Canceladas</div><div class="dash-kpi-value"><span class="dash-kpi-perc" id="kpi-cancel-perc">'.$pct_canceladas.'%</span><small class="dash-kpi-abs" id="kpi-cancel-abs">'.(int)$cnt_canceladas.' de '.(int)$cnt_total.'</small></div><div class="dash-kpi-bar" aria-hidden="true"><span id="bar-cancel" style="width:'.$pct_canceladas.'%"></span></div></div></div><div class="dash-composition" aria-label="Composição por status (visível)"><div class="dash-stacked"><span id="seg-dia" class="seg seg-dia" style="width:'.$pct_dia.'%"></span><span id="seg-atraso" class="seg seg-atraso" style="width:'.$pct_atraso.'%"></span><span id="seg-cancel" class="seg seg-cancel" style="width:'.$pct_canceladas.'%"></span></div><div class="dash-comp-label">Composição do conjunto visível (Em Dia / Em Atraso / Canceladas)</div></div><div class="dash-summary-note" id="dash-summary-note">Mostrando '.(int)$cnt_total.' de '.(int)$cnt_total.' assinaturas</div>';

    // ===== Barra de busca + Histórico =====
    $search = '<div class="dash-wrap">'.$summary.'
    <!-- 1ª linha: filtros normais -->
    <div class="dash-search" role="search" aria-label="Filtrar assinantes">
      <select id="dash-course-filter" class="dash-input dash-select" aria-label="Filtrar por ID do curso">'.$course_options.'</select>
      <input type="text" id="dash-month-picker" class="dash-input dash-select" placeholder="Selecionar mês/ano" aria-label="Selecionar mês e ano" readonly>
      <input type="text" id="dash-search-input" class="dash-input" placeholder="Buscar por nome, e-mail ou CPF" aria-label="Buscar por nome, e-mail ou CPF">
      <button type="button" id="dash-search-btn" class="dash-btn">Buscar</button>
      <button type="button" id="dash-clear-btn" class="dash-btn" aria-label="Limpar filtros">Limpar</button>

      <!-- baixar csv -->
      <button type="button" id="dash-export-xls-btn" class="dash-btn" aria-label="Baixar relatório XLS">Baixar relatório (XLS)</button>
    </div>

    <!-- 2ª linha: controles do modo histórico -->
    <div class="dash-hist-row" role="group" aria-label="Histórico">
      <input type="date" id="dash-snapshot-date" class="dash-input dash-select" aria-label="Data de referência (histórico)" placeholder="AAAA-MM-DD">
      <button type="button" id="dash-snapshot-apply" class="dash-btn" aria-label="Aplicar histórico">Histórico</button>
      <button type="button" id="dash-snapshot-clear" class="dash-btn" aria-label="Sair do histórico">Sair do histórico</button>
    </div>

    <!-- Banner do histórico -->
    <div id="dash-hist-banner" class="dash-hist-banner" style="display:none" role="status" aria-live="polite">
      Modo histórico ativo em <strong id="dash-hist-date-label"></strong>. Os filtros normais ficam temporariamente ignorados.
      </div>';


    // ===== Legenda =====
    $legend = '<div style="display:flex;gap:14px;align-items:center;margin:4px 0 10px;flex-wrap:wrap;font-size:.9rem;color:#374151">
      <span><span class="status-box paid" tabindex="0" aria-label="Pago"></span> Pago</span>
      <span><span class="status-box overdue" tabindex="0" aria-label="Em atraso"></span> Em atraso</span>
      <span><span class="status-box pending" tabindex="0" aria-label="Pendente"></span> Pendente</span>
      <span><span class="status-box future" tabindex="0" aria-label="Não criada"></span> Não criada</span>
    </div>';

    // Inicia HTML
    $html  = $css . $search . $legend;

    // ===== Tabela builder =====
    $build_row = function(array $sub, array $payments, int $product_id) use ($agora, $hojeYmd) {
        $subscription_pk_id = (int)($sub['id'] ?? 0);
        $base_id = dash_maybe_get_parent_product_id($product_id);

        $totalInstallments = 6;
        $cycles = null;
        if ($product_id) {
            $cycles = dash_get_subscription_cycles_from_product($product_id);
            if ($cycles !== null) $totalInstallments = ($cycles > 0) ? (int)$cycles : max(count($payments),6);
        }
        if ((!$product_id || $cycles === null) && !empty($sub['order_id'])) {
            $maybe_cycles = dash_get_cycles_from_wc_subscription_order($sub['order_id']);
            if ($maybe_cycles !== null) $totalInstallments = ($maybe_cycles > 0) ? (int)$maybe_cycles : max(count($payments),6);
        }
        if (count($payments) > $totalInstallments) $totalInstallments = count($payments);

        $installments = array_fill(0,$totalInstallments,null);
        foreach ($payments as $p) {
            $idx = (isset($p['installmentNumber']) && is_numeric($p['installmentNumber'])) ? ((int)$p['installmentNumber'] - 1) : null;
            if ($idx === null || $idx < 0 || $idx >= $totalInstallments) $idx = array_search(null,$installments,true);
            if ($idx !== false) $installments[$idx] = $p;
        }

        $cpf_display = $sub['cpf'] ?? ($sub['customer_cpf'] ?? '—');
        $r  = '<tr id="sub-'.(int)$subscription_pk_id.'" data-course-id="'.esc_attr($product_id ?: 0).'" data-course-base-id="'.esc_attr($base_id ?: 0).'">';
        $r .= '<td>'.esc_html($sub['customer_name'] ?? '—').'</td>';
        $r .= '<td>'.esc_html($sub['customer_email'] ?? '—').'</td>';
        $r .= '<td>'.esc_html($cpf_display).'</td>';

        $r .= '<td>';

foreach ($installments as $p) {
    // defaults
    $cls = 'future';
    $status_tip = 'Não criada';
    $tooltip = 'Parcela não criada';

    // datas/labels
    $cre_raw = '';     $created_ymd = '';
    $due_ts  = 0;      $due_ymd     = '';
    $pay_raw = '';     $paid_ymd    = '';
    $venc_label = '—'; $pag_label   = '—'; $created_label = '—';

    if ($p) {
        $val = isset($p['value']) ? number_format((float)$p['value'], 2, ',', '.') : '0,00';

        // criação
        $cre_raw = $p['created'] ?? ($p['createdAt'] ?? '');
        if ($cre_raw) {
            $created_ymd  = date_i18n('Y-m-d', strtotime($cre_raw));
            $created_label = date_i18n('d/m/Y - H:i', strtotime($cre_raw));
        }

        // vencimento
        $due_ts  = strtotime($p['dueDate'] ?? '');
        $orig_ts = strtotime($p['originalDueDate'] ?? '');
        $due_ts  = $due_ts ?: $orig_ts;
        if ($due_ts) {
            $due_ymd    = date_i18n('Y-m-d', $due_ts);
            $venc_label = date_i18n('d/m/Y', $due_ts);
        }

        // pagamento
        $pay_raw = $p['paymentDate'] ?? '';
        if ($pay_raw) {
            $paid_ymd  = date_i18n('Y-m-d', strtotime($pay_raw));
            $pag_label = date_i18n('d/m/Y', strtotime($pay_raw));
        }

        // status "atual" (visão normal)
        $p_stat   = strtoupper($p['paymentStatus'] ?? '');
        $isPaid   = in_array($p_stat, ['PAYED','PAID','RECEIVED','RECEIVED_IN_CASH','CONFIRMED'], true);
        $hojeYmd  = date_i18n('Y-m-d', current_time('timestamp'));
        $isOver   = (!$isPaid && ($p_stat === 'OVERDUE' || ($due_ymd && $due_ymd < $hojeYmd)));

        if ($isPaid)       { $cls='paid';    $status_tip='Pago'; }
        elseif ($isOver)   { $cls='overdue'; $status_tip='Em atraso'; }
        else               { $cls='pending'; $status_tip='Pendente'; }

        $tooltip = "Status: {$status_tip}\nValor: R$ {$val}\nCriação: {$created_label}\nVencimento: {$venc_label}\nPagamento: {$pag_label}";
    }

    $r .= '<span class="status-box '.esc_attr($cls).'"'
        .' data-tooltip="'.esc_attr($tooltip).'"'
        .' tabindex="0" aria-label="'.esc_attr($status_tip).'"'
        // antigos (pt-BR, ainda usados pelo filtro mensal e tooltips)
        .' data-de-criacao="'.esc_attr($created_label !== '—' ? $created_label : '').'"'
        .' data-de-vencimento="'.esc_attr($due_ts ? date_i18n('d/m/Y', $due_ts) : '').'"'
        .' data-de-pagamento="'.esc_attr($pay_raw ? date_i18n('d/m/Y', strtotime($pay_raw)) : '').'"'
        .' data-month="'.esc_attr($due_ts ? date_i18n('Y-m', $due_ts) : '').'"'
        .' data-date="'.esc_attr($due_ymd).'"'     /* <<< AQUI sem a barra invertida */
        // NOVOS (ISO) — usados pelo histórico
        .' data-created-ymd="'.esc_attr($created_ymd).'"'
        .' data-due-ymd="'.esc_attr($due_ymd).'"'
        .' data-paid-ymd="'.esc_attr($paid_ymd).'"'
        .'></span>';

}

        $r .= '</td>';

        $stat = strtoupper(trim($sub['status'] ?? ''));
        $r .= '<td>';
        if (in_array($stat,['CANCELLED','CANCELED','EXPIRED'],true)) {
            $date = $sub['cancelled_at'] ?? ($sub['cancelledAt'] ?? ($sub['canceledAt'] ?? ($sub['expiredAt'] ?? ($sub['updated'] ?? ''))));
            $r .= $date ? 'Cancelado em '.esc_html(date_i18n('d/m/Y', strtotime($date))) : 'Cancelado';
        } else {
            $r .= ($stat === 'ACTIVE') ? 'Ativo' : (($stat === 'INACTIVE') ? 'Inativo' : esc_html(ucfirst(strtolower($sub['status'] ?? '—'))));
        }
        $r .= '</td></tr>';
        return $r;
    };

    $html = $css . $search . $legend;

    foreach (['em_atraso'=>'Em Atraso','em_dia'=>'Em Dia','canceladas'=>'Canceladas'] as $key=>$titulo) {
        $html .= '<h3 class="dash-h3">Assinaturas — '.esc_html($titulo).'</h3>';
        $html .= "<table id='dash-table-{$key}' class='dash-table' role='table' aria-label='Assinaturas {$titulo}'><thead><tr><th>Nome</th><th>E-mail</th><th>CPF</th><th>Pagamentos</th><th>Status</th></tr></thead><tbody>";
        if (empty($grupos[$key])) {
            $html .= "<tr><td colspan='5'><em>Nenhuma assinatura nesta categoria.</em></td></tr>";
        } else {
            foreach ($grupos[$key] as $entry) { $html .= $build_row($entry['sub'],$entry['payments'],(int)$entry['product_id']); }
        }
        $html .= '</tbody></table>';
    }

    // ===== JS =====
ob_start();
?>
<script>
(function(){
  function $id(id){ return document.getElementById(id); }
  function onReady(fn){
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once:true });
    } else {
      fn();
    }
  }

  // Utils
  function normalize(s){ return (s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase(); }
  function digitsOnly(s){ return String(s||'').replace(/\D+/g,''); }

  function getSelectedMonth(){
    var el = $id('dash-month-picker');
    return el ? String(el.value||'').trim() : '';
  }

  // ====== MÊS por CRIAÇÃO (prioriza data-de-criacao) ======
  function getBoxCreationYM(box){
    var br = box.getAttribute('data-de-criacao') || '';
    if (br) {
      var m = String(br).trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/);
      if (m) {
        var mm = ('0' + parseInt(m[2], 10)).slice(-2);
        return m[3] + '-' + mm;
      }
    }
    var iso = box.getAttribute('data-created-ymd') || '';
    var mi = iso.match(/^(\d{4})-(\d{2})-\d{2}$/);
    if (mi) return mi[1] + '-' + mi[2];
    return '';
  }

  // cache/restauração visual
  function captureOriginalOnce(box){
    if(box.dataset && box.dataset._captured==='1') return;
    var origStatus = 'future';
    if(box.classList.contains('paid')) origStatus='paid';
    else if(box.classList.contains('overdue')) origStatus='overdue';
    else if(box.classList.contains('pending')) origStatus='pending';
    else if(box.classList.contains('future')) origStatus='future';

    box.dataset.origStatus  = origStatus;
    box.dataset.origTooltip = box.getAttribute('data-tooltip') || '';
    box.dataset.origAria    = box.getAttribute('aria-label') || '';
    box.dataset._captured   = '1';
  }
  function setStatus(box, status){
    box.classList.remove('paid','overdue','pending','future');
    box.classList.add(status);
  }
  function restoreOriginal(box){
    var s = (box.dataset && box.dataset.origStatus) ? box.dataset.origStatus : 'future';
    setStatus(box, s);
    if(box.dataset){
      var tt = box.dataset.origTooltip || '';
      var al = box.dataset.origAria || '';
      if(tt) box.setAttribute('data-tooltip', tt); else box.removeAttribute('data-tooltip');
      if(al) box.setAttribute('aria-label', al); else box.removeAttribute('aria-label');
    }
    box.removeAttribute('title');
    box.classList.remove('muted-future');
  }
  function muteAsFuture(box){
    setStatus(box, 'future');
    box.setAttribute('data-tooltip','');
    box.setAttribute('aria-label','Sem dados');
    box.removeAttribute('title');
    box.classList.add('muted-future');
  }

  // KPIs
  function countVisibleRows(tableId){
    var t=$id(tableId); if(!t) return 0;
    var rows=t.querySelectorAll('tbody tr[id^="sub-"]');
    var n=0; rows.forEach(function(r){ if(r.style.display!=='none') n++; });
    return n;
  }
  function setText(id,txt){var el=$id(id); if(el){el.textContent=txt;}}
  function setBar(id,pct){var el=$id(id); if(el){el.style.width=Math.max(0,Math.min(100,pct))+'%';}}
  function pct(part,total){return total?Math.round((part/total)*100):0;}
  function updateSummary(){
    var visDia=countVisibleRows('dash-table-em_dia');
    var visAtr=countVisibleRows('dash-table-em_atraso');
    var visCanc=countVisibleRows('dash-table-canceladas');
    var visTotal=visDia+visAtr+visCanc;
    var pDia=pct(visDia,visTotal), pAtr=pct(visAtr,visTotal), pCan=pct(visCanc,visTotal);

    setText('kpi-total-perc',(visTotal>0?'100%':'0%'));
    setText('kpi-dia-perc',pDia+'%'); setText('kpi-atraso-perc',pAtr+'%'); setText('kpi-cancel-perc',pCan+'%');
    setText('kpi-total-abs',visTotal+' assinaturas');
    setText('kpi-dia-abs',visDia+' de '+visTotal);
    setText('kpi-atraso-abs',visAtr+' de '+visTotal);
    setText('kpi-cancel-abs',visCanc+' de '+visTotal);

    setBar('bar-total',visTotal>0?100:0);
    setBar('bar-dia',pDia);
    setBar('bar-atraso',pAtr);
    setBar('bar-cancel',pCan);

    setBar('seg-dia',pDia);
    setBar('seg-atraso',pAtr);
    setBar('seg-cancel',pCan);
  }
  window.updateSummary = updateSummary;

  function buildCourseMap(){
    var s=$id('dash-course-filter'), map={};
    if(s){ for(var i=0;i<s.options.length;i++){ var opt=s.options[i]; map[String(opt.value||'')]=opt.textContent||opt.innerText||opt.text||''; } }
    return map;
  }
  function setRowTooltips(courseMap){
    var rows=document.querySelectorAll('.dash-table tbody tr[id^="sub-"]');
    rows.forEach(function(tr){
      var cid=String(tr.getAttribute('data-course-id')||'');
      var cbase=String(tr.getAttribute('data-course-base-id')||'');
      var name=(courseMap[String(cid)]||'')||(courseMap[String(cbase)]||'');
      if(name){ tr.setAttribute('data-tooltip','Curso: '+name); tr.removeAttribute('title'); }
      else{ tr.removeAttribute('data-tooltip'); tr.removeAttribute('title'); }
    });
  }

  var ROWS = [];

  // ====== FILTRO principal (mês por CRIAÇÃO) ======
  function applyFilter(){
    ROWS = [].slice.call(document.querySelectorAll('.dash-table tbody tr[id^="sub-"]'));

    var inp=$id('dash-search-input'), sel=$id('dash-course-filter');
    var rawQ=inp?inp.value.trim():'';
    var q=normalize(rawQ), qDigits=digitsOnly(rawQ);
    var selectedId=sel?String(sel.value||'').toLowerCase():'';
    var selectedMonth=getSelectedMonth(); // '' ou 'YYYY-MM'
    var inHist = !!window.__dashHistActive;

    ROWS.forEach(function(tr){
      var c=tr.cells; if(!c||c.length<3){ tr.style.display=''; return; }

      // === NOVO: respeita ocultação do histórico por linha ===
      var histHide = tr.classList.contains('hist-hide-row');

      // texto/curso
      var nome=normalize(c[0].textContent), email=normalize(c[1].textContent);
      var cpfText=c[2].textContent||'', cpfNorm=normalize(cpfText), cpfDigits=digitsOnly(cpfText);
      var rid=(tr.id||'').toLowerCase(),
          cid=String(tr.getAttribute('data-course-id')||'').toLowerCase(),
          cbase=String(tr.getAttribute('data-course-base-id')||'').toLowerCase();

      var matchText=(q==='')||(nome.indexOf(q)>-1)||(email.indexOf(q)>-1)||(cpfNorm.indexOf(q)>-1)||(qDigits&&cpfDigits.indexOf(qDigits)>-1)||(rid.indexOf(q)>-1)||(cid.indexOf(q)>-1)||(cbase.indexOf(q)>-1);
      var matchCourse=(selectedId===''||cid===selectedId||cbase===selectedId);

      // mês por CRIAÇÃO (comportamento distinto no histórico)
      var boxes=tr.querySelectorAll('.status-box');
      var matchesInMonth=0;

      boxes.forEach(function(box){
        captureOriginalOnce(box);
        box.classList.remove('month-dim-hide');

        if(!selectedMonth){
          if(!inHist){ restoreOriginal(box); }
          return;
        }

        var ym = (function(b){
          var br = b.getAttribute('data-de-criacao') || '';
          if (br) {
            var m = String(br).trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/);
            if (m) return m[3] + '-' + ('0'+parseInt(m[2],10)).slice(-2);
          }
          var iso = b.getAttribute('data-created-ymd') || '';
          var mi = iso.match(/^(\d{4})-(\d{2})-\d{2}$/);
          return mi ? (mi[1] + '-' + mi[2]) : '';
        })(box);

        var isMatch = (ym && ym === selectedMonth);

        if (inHist) {
          if (isMatch) { matchesInMonth++; }
          else { box.classList.add('month-dim-hide'); }
        } else {
          if (isMatch) { restoreOriginal(box); matchesInMonth++; }
          else { muteAsFuture(box); }
        }
      });

      var passMonth = (!selectedMonth) ? true : (matchesInMonth > 0);
      tr.style.display = (matchText && matchCourse && passMonth && !histHide) ? '' : 'none';
    });

    if (window.dashRegroupRows) { window.dashRegroupRows(); } else { if (window.updateSummary) { window.updateSummary(); } }
  }

  function clearFilter(){
  var inp=$id('dash-search-input'); if(inp){inp.value='';}
  var sel=$id('dash-course-filter'); if(sel){sel.value='';}

  var mon=$id('dash-month-picker');
  if(mon){
    // 1) zera o valor do input
    mon.value = '';

    // 2) se estiver usando jQuery UI Datepicker, zera a “data selecionada” interna
    if (typeof jQuery !== 'undefined') {
      var $mon = jQuery('#dash-month-picker');
      if ($mon.length && $mon.data('datepicker')) {
        $mon.datepicker('setDate', null); // limpa seleção interna
        $mon.val('');                     // garante que o input fique vazio
      }
    }

    // 3) dispara os mesmos eventos que o applyFilter() já escuta
    //    (assim o filtro de mês sai 100% do estado)
    mon.dispatchEvent(new Event('dash:monthChanged'));
    mon.dispatchEvent(new Event('change'));
    mon.dispatchEvent(new Event('input'));
  }

  // limpa efeitos visuais e exibe todas as linhas
  ROWS = [].slice.call(document.querySelectorAll('.dash-table tbody tr[id^="sub-"]'));
  ROWS.forEach(function(tr){
    tr.classList.remove('hist-hide-row');
    tr.style.display='';
    tr.querySelectorAll('.status-box').forEach(function(b){
      captureOriginalOnce(b);
      b.classList.remove('month-dim-hide');
      restoreOriginal(b);
    });
  });

  // se houver reagrupamento dinâmico, mantenha coerente
  if (window.dashRegroupRows) { window.dashRegroupRows(); }

  updateSummary();
  if(inp){ inp.focus(); }
}
  window.dashApplyFilter = applyFilter;

    // ======== EXPORTAÇÃO XLS (somente o que está visível), agrupando por curso ========
  function collectVisibleRows(){
    return [].slice.call(document.querySelectorAll('.dash-table tbody tr[id^="sub-"]'))
      .filter(function(tr){ return tr.style.display !== 'none' && !tr.classList.contains('hist-hide-row'); });
  }
  function collectVisibleBoxes(tr){
    return [].slice.call(tr.querySelectorAll('.status-box'))
      .filter(function(box){ return !box.classList.contains('month-dim-hide'); });
  }
  function statusLabelFromClass(box){
    if (box.classList.contains('paid')) return 'Pago';
    if (box.classList.contains('overdue')) return 'Em atraso';
    if (box.classList.contains('pending')) return 'Pendente';
    return 'Não criada';
  }
  function colorFromClass(box){ // fundo da célula
    if (box.classList.contains('paid')) return '#16a34a';     // verde
    if (box.classList.contains('overdue')) return '#e11d48';  // vermelho
    if (box.classList.contains('pending')) return '#f59e0b';  // amarelo
    return '#e5e7eb';                                         // cinza (não criada)
  }
  function fontColorFor(bg){ // contraste legível
    // cores escuras -> branco | claras -> preto
    // heurística simples (luma aproximada)
    var c = bg.replace('#','');
    if (c.length === 3) c = c.split('').map(function(x){return x+x;}).join('');
    var r = parseInt(c.substr(0,2),16), g = parseInt(c.substr(2,2),16), b = parseInt(c.substr(4,2),16);
    var luma = 0.2126*r + 0.7152*g + 0.0722*b;
    return luma < 140 ? '#ffffff' : '#111111';
  }
  function formatDateBR(isoOrBr){
    var s = String(isoOrBr||'').trim();
    var mISO = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (mISO) return mISO[3]+'/'+mISO[2]+'/'+mISO[1];
    var mBR = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (mBR) return ('0'+mBR[1]).slice(-2)+'/'+('0'+mBR[2]).slice(-2)+'/'+mBR[3];
    return '';
  }
  function buildCourseMap(){
    var s=document.getElementById('dash-course-filter'), map={};
    if(s){ for(var i=0;i<s.options.length;i++){ var opt=s.options[i]; map[String(opt.value||'')]=opt.textContent||opt.innerText||opt.text||''; } }
    return map;
  }
  function getCourseNameForRow(tr, courseMap){
    var cid   = String(tr.getAttribute('data-course-id')||'');
    var cbase = String(tr.getAttribute('data-course-base-id')||'');
    return (courseMap[cid] || courseMap[cbase] || 'Curso não identificado');
  }

  function buildXlsHtmlFromVisible(){
    var courseMap = buildCourseMap();
    var rows = collectVisibleRows();

    // Agrupa linhas por curso
    var groups = {};        // { 'Nome do Curso' : [tr, tr, ...] }
    var maxBoxesByCourse={}; // { 'Nome do Curso' : maxVisiveis }
    rows.forEach(function(tr){
      var name = getCourseNameForRow(tr, courseMap);
      if(!groups[name]) groups[name] = [];
      groups[name].push(tr);
    });

    // Descobre o máximo de parcelas visíveis por curso (para criar colunas fixas)
    Object.keys(groups).forEach(function(course){
      var maxN = 0;
      groups[course].forEach(function(tr){
        var n = collectVisibleBoxes(tr).length;
        if(n > maxN) maxN = n;
      });
      maxBoxesByCourse[course] = maxN;
    });

    // Cabeçalho CSS para o Excel (tabela HTML)
    var css = `
      <style>
        table { border-collapse: collapse; width:100%; font-family: Arial, sans-serif; font-size: 12px; }
        th, td { border:1px solid #444; padding:6px 8px; vertical-align: top; }
        th { background:#0a66c2; color:#fff; text-transform:uppercase; font-weight:700; }
        .course-title { background:#eef2f7; font-weight:700; font-size:14px; }
        .muted { color:#555; }
      </style>
    `;

    // Captura contexto atual (opcional, cabeçalho do relatório)
    var sel = document.getElementById('dash-course-filter');
    var inp = document.getElementById('dash-search-input');
    var mon = document.getElementById('dash-month-picker');
    var his = document.getElementById('dash-snapshot-date');
    var histOn = !!window.__dashHistActive;

    var contextHtml = `
      <table>
        <tr><td colspan="3"><strong>Relatório do Dashboard (itens visíveis)</strong></td></tr>
        <tr><td>Curso selecionado:</td><td colspan="2">${sel && sel.options[sel.selectedIndex] ? (sel.options[sel.selectedIndex].text || '') : 'Todos'}</td></tr>
        <tr><td>Busca:</td><td colspan="2">${inp ? (inp.value || '—') : '—'}</td></tr>
        <tr><td>Mês (Criação):</td><td colspan="2">${mon ? (mon.value || '—') : '—'}</td></tr>
        <tr><td>Histórico:</td><td colspan="2">${histOn ? (his && his.value ? formatDateBR(his.value) : 'Ativo') : 'Inativo'}</td></tr>
      </table>
      <br/>
    `;

    // Para cada curso, monta uma tabela com colunas fixas:
    // | Nome | E-mail | CPF | Status Assinatura | Parcela 1 | Parcela 2 | ... |
    // Cada "Parcela N" traz "Status | Criação: dd/mm/aaaa | Venc: ... | Pag: ..." e é colorida.
    var body = '';
    Object.keys(groups).sort().forEach(function(course){
      var maxCols = maxBoxesByCourse[course] || 0;

      var theadCols = `
        <tr>
          <th>Nome</th>
          <th>E-mail</th>
          <th>CPF</th>
          <th>Status da Assinatura</th>
          ${Array.from({length:maxCols}).map(function(_,i){ return '<th>Parcela '+(i+1)+'</th>'; }).join('')}
        </tr>
      `;

      var trsHtml = groups[course].map(function(tr){
        var tds = tr.getElementsByTagName('td');
        var nome  = tds[0] ? tds[0].textContent.trim() : '';
        var email = tds[1] ? tds[1].textContent.trim() : '';
        var cpf   = tds[2] ? tds[2].textContent.trim() : '';
        var stat  = tds[4] ? tds[4].textContent.trim() : '';

        // caixas visíveis, na ordem
        var boxes = collectVisibleBoxes(tr);

        // Constrói as células de parcelas (até maxCols), pintando o fundo
        var parcelaTds = '';
        for (var i=0;i<maxCols;i++){
          if (i < boxes.length){
            var box = boxes[i];
            var bg  = colorFromClass(box);
            var fc  = fontColorFor(bg);

            var st  = statusLabelFromClass(box);
            var cre = box.getAttribute('data-created-ymd') || box.getAttribute('data-de-criacao') || '';
            var due = box.getAttribute('data-due-ymd')     || box.getAttribute('data-de-vencimento') || '';
            var pay = box.getAttribute('data-paid-ymd')    || box.getAttribute('data-de-pagamento') || '';

            var txt = [
              st,
              (formatDateBR(cre) ? 'Criação: '+formatDateBR(cre) : ''),
              (formatDateBR(due) ? 'Venc: '+formatDateBR(due) : ''),
              (formatDateBR(pay) ? 'Pag: '+formatDateBR(pay) : '')
            ].filter(Boolean).join(' | ');

            parcelaTds += `<td style="background:${bg};color:${fc}">${txt}</td>`;
          } else {
            parcelaTds += '<td class="muted">—</td>';
          }
        }

        return `
          <tr>
            <td>${nome}</td>
            <td>${email}</td>
            <td>${cpf}</td>
            <td>${stat}</td>
            ${parcelaTds}
          </tr>
        `;
      }).join('');

      body += `
        <table>
          <tr><td class="course-title" colspan="${4 + maxCols}">Curso: ${course}</td></tr>
          <thead>${theadCols}</thead>
          <tbody>${trsHtml || '<tr><td colspan="'+(4+maxCols)+'"><em>Nenhum registro visível</em></td></tr>'}</tbody>
        </table>
        <br/>
      `;
    });

    // Envelopa em HTML “Excel”
    var html = `
      <html xmlns:o="urn:schemas-microsoft-com:office:office"
            xmlns:x="urn:schemas-microsoft-com:office:excel"
            xmlns="http://www.w3.org/TR/REC-html40">
        <head>
          <meta charset="utf-8" />
          ${css}
        </head>
        <body>
          ${contextHtml}
          ${body || '<p><em>Nenhum dado visível para exportar.</em></p>'}
        </body>
      </html>
    `;
    return html;
  }

  function downloadXlsVisible(){
    var html = buildXlsHtmlFromVisible();
    var blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    var ts   = new Date();
    var iso  = ts.toISOString().slice(0,19).replace(/[:T]/g,'-');
    a.href = url;
    a.download = 'relatorio-dashboard-visivel-' + iso + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  function init(){
    document.addEventListener('click', function(ev){
      var t = ev.target;
      if (!t) return;
      if (t.id === 'dash-search-btn') {
        ev.preventDefault();
        applyFilter();
      } else if (t.id === 'dash-clear-btn') {
        ev.preventDefault();
        clearFilter();
      }
    });

        // Botão de exportação XLS
    var expX = $id('dash-export-xls-btn');
    if (expX) {
      expX.addEventListener('click', function(ev){
        ev.preventDefault();
        downloadXlsVisible();
      });
    }

    var mon=$id('dash-month-picker');
    if(mon){
      mon.addEventListener('dash:monthChanged', applyFilter);
      mon.addEventListener('change', applyFilter);
      mon.addEventListener('input', applyFilter);
    }

    setRowTooltips(buildCourseMap());
    ROWS = [].slice.call(document.querySelectorAll('.dash-table tbody tr[id^="sub-"]'));
    updateSummary();
  }
  window.dashClearFilter = clearFilter; 

  onReady(init);
})();
</script>

<script>
/* Reagrupamento dinâmico de linhas (depois de mês/histórico) */
(function(){
  function $id(id){ return document.getElementById(id); }

  function _closestTableId(tr){
    var t = tr ? tr.closest('table') : null;
    return t ? (t.id || '') : '';
  }

  function _rowIsCancelled(tr){
    var tds = tr ? tr.getElementsByTagName('td') : null;
    if(!tds || tds.length < 5) return false;
    var txt = (tds[4].textContent || '').toLowerCase();
    if(txt.indexOf('cancelado') > -1) return true;
    if(txt.indexOf('cancelada') > -1) return true;
    return false;
  }

  // retorna true/false se há overdue visível; null = ignorar (linha oculta ou nenhuma box visível)
  function _rowHasVisibleOverdue(tr){
    if(!tr || tr.style.display === 'none') return null;

    var boxes = tr.querySelectorAll('.status-box');
    var anyVisible = false;
    var i = 0;
    for(i=0;i<boxes.length;i++){
      var b = boxes[i];
      if(b.classList.contains('month-dim-hide')) { continue; } // escondida pelo mês
      anyVisible = true;
      if(b.classList.contains('overdue')) { return true; }
    }
    if(!anyVisible) return null; // nada visível por causa do mês/histórico
    return false;
  }

  function dashRegroupRows(){
    var tbDia = $id('dash-table-em_dia');
    var tbAtr = $id('dash-table-em_atraso');
    var tbCan = $id('dash-table-canceladas');

    var tbodyDia = tbDia ? tbDia.querySelector('tbody') : null;
    var tbodyAtr = tbAtr ? tbAtr.querySelector('tbody') : null;
    var tbodyCan = tbCan ? tbCan.querySelector('tbody') : null;

    var rows = document.querySelectorAll('.dash-table tbody tr[id^="sub-"]');
    rows.forEach(function(tr){
      if(tr.style.display === 'none') return; // já ocultada por busca/curso/histórico

      // 1) Canceladas sempre vencem
      if(_rowIsCancelled(tr)){
        if(tbodyCan){
          var cur = _closestTableId(tr);
          if(cur !== 'dash-table-canceladas'){ tbodyCan.appendChild(tr); }
        }
        return;
      }

      // 2) Se tem overdue visível => em_atraso; senão => em_dia
      var ov = _rowHasVisibleOverdue(tr);
      if(ov === null) return; // nenhuma parcela visível, não mexe

      if(ov){
        if(tbodyAtr){
          var curA = _closestTableId(tr);
          if(curA !== 'dash-table-em_atraso'){ tbodyAtr.appendChild(tr); }
        }
      }else{
        if(tbodyDia){
          var curD = _closestTableId(tr);
          if(curD !== 'dash-table-em_dia'){ tbodyDia.appendChild(tr); }
        }
      }
    });

    if(window.updateSummary){ window.updateSummary(); }
  }

  // exporta p/ outros blocos
  window.dashRegroupRows = dashRegroupRows;
})();
</script>


<script>
/* Datepicker: limita aos meses realmente existentes nas status-box. Fallback para <input type="month"> */
(function($){
  function hasDP(){ return $.datepicker && typeof $.datepicker === 'object'; }
  function pad2(n){ return (n<10?'0':'')+n; }
  function ym(y,mIdx){ return y+'-'+pad2(mIdx+1); }       // mIdx 0..11
  function ymToDate(ym){ var m=String(ym||'').match(/^(\d{4})-(\d{2})$/); return m ? new Date(parseInt(m[1],10), parseInt(m[2],10)-1, 1) : null; }

  // >>>> COLETA MESES POR CRIAÇÃO (prioriza data-de-criacao) <<<<
function collectAllowedMonthsByCreation(){
  function pad2(n){ return (n<10?'0':'')+n; }
  var set = new Set();

  document.querySelectorAll('.status-box').forEach(function(box){
    // 1) BR primeiro
    var br = box.getAttribute('data-de-criacao') || '';
    if (br) {
      var mb = String(br).match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/);
      if (mb) {
        set.add(mb[3] + '-' + pad2(parseInt(mb[2],10)));
        return;
      }
    }
    // 2) Fallback ISO
    var iso = box.getAttribute('data-created-ymd') || '';
    var mi = String(iso).match(/^(\d{4})-(\d{2})-\d{2}$/);
    if (mi) {
      set.add(mi[1] + '-' + mi[2]);
    }
  });

  var arr = Array.from(set);
  arr.sort(function(a,b){ return a<b ? -1 : (a>b ? 1 : 0); });
  return arr;
}
  function enforceMonthOptions(dpDiv, byYear){
    var $ySel = dpDiv.find('.ui-datepicker-year');
    var $mSel = dpDiv.find('.ui-datepicker-month');
    $ySel.find('option').each(function(){
      var y = $(this).val();
      $(this).prop('disabled', !byYear[y]);
    });
    var curY = parseInt($ySel.val(),10);
    if(!byYear[curY]){
      var years = Object.keys(byYear).map(function(s){return parseInt(s,10);}).sort(function(a,b){return a-b;});
      if(years.length){ $ySel.val(String(years[0])); curY = years[0]; }
    }
    $mSel.find('option').each(function(idx){
      $(this).prop('disabled', !(byYear[curY] && byYear[curY][idx]));
    });
  }

  $(function(){
    var $inp = $('#dash-month-picker');
    if(!$inp.length) return;

    var allowed = collectAllowedMonthsByCreation();
    if(!allowed.length){
      return;
    }

    var byYear = {};
    allowed.forEach(function(k){
      var y = k.slice(0,4), mi = parseInt(k.slice(5,7),10)-1;
      if(!byYear[y]) byYear[y] = {};
      byYear[y][mi] = true;
    });

    var allowedSet = new Set(allowed);
    var minYm = allowed[0], maxYm = allowed[allowed.length-1];
    var minDate = ymToDate(minYm), maxDate = ymToDate(maxYm);

    var minLabel = minYm.slice(5,7) + '/' + minYm.slice(0,4);
    var maxLabel = maxYm.slice(5,7) + '/' + maxYm.slice(0,4);
    if(!$inp.val()){ $inp.attr('placeholder', 'De '+minLabel+' a '+maxLabel); }

    if(!hasDP()){
      try{
        $inp.attr('type','month').removeAttr('readonly').attr('min', minYm).attr('max', maxYm);
        $inp.on('change', function(){
          var v = $(this).val();
          if(!allowedSet.has(v)){ $(this).val(''); }
          this.dispatchEvent(new Event('input'));
        });
      }catch(e){}
      return;
    }

    $inp.datepicker({
      dateFormat: 'yy-mm',
      changeMonth: true,
      changeYear: true,
      showButtonPanel: true,
      minDate: minDate,
      maxDate: maxDate,
      beforeShow: function(input, inst){ setTimeout(function(){ enforceMonthOptions(inst.dpDiv, byYear); }, 0); },
      onChangeMonthYear: function(year, month, inst){ setTimeout(function(){ enforceMonthOptions(inst.dpDiv, byYear); }, 0); },
      onClose: function(dateText, inst){
        var dpDiv = inst.dpDiv;
        var mIdx  = parseInt(dpDiv.find('.ui-datepicker-month :selected').val(),10);
        var yVal  = parseInt(dpDiv.find('.ui-datepicker-year :selected').val(),10);
        var key   = ym(yVal, mIdx);

        if(!allowedSet.has(key)){
          if($(this).val() && !allowedSet.has($(this).val())) $(this).val('');
          return;
        }
        var d = ymToDate(key);
        if(d < minDate) d = minDate;
        if(d > maxDate) d = maxDate;
        $(this).datepicker('setDate', d);
        $(this).val(key);
        $(this).trigger('dash:monthChanged', [key]);
      }
    });

    $inp.on('focus', function(){
      var v = $inp.val();
      var d = v ? ymToDate(v) : minDate;
      $inp.datepicker('setDate', d);
      var inst = $inp.data('datepicker');
      if(inst){ setTimeout(function(){ enforceMonthOptions(inst.dpDiv, byYear); }, 0); }
    });
  });
})(jQuery);
</script>

<script>
/* Histórico (criação + vencimento + pagamento), sem botões — com atualização interna */
(function(){
  function $id(id){ return document.getElementById(id); }

  // ===== helpers visuais =====
  function setStatus(box, status){
    box.classList.remove('paid','overdue','pending','future');
    box.classList.add(status);
  }
  function captureOriginalOnce(box){
    if (box.dataset && box.dataset._histcap==='1') return;
    var orig = box.classList.contains('paid')    ? 'paid'
             : box.classList.contains('overdue') ? 'overdue'
             : box.classList.contains('pending') ? 'pending'
             : 'future';
    box.dataset._histcap   = '1';
    box.dataset._origClass = orig;
    box.dataset._origTip   = box.getAttribute('data-tooltip') || '';
    box.dataset._origAria  = box.getAttribute('aria-label')   || '';
  }
  function restoreOriginal(box){
    var c = box.dataset._origClass || 'future';
    setStatus(box, c);
    if (box.dataset._origTip){ box.setAttribute('data-tooltip', box.dataset._origTip); } else { box.removeAttribute('data-tooltip'); }
    if (box.dataset._origAria){ box.setAttribute('aria-label', box.dataset._origAria); } else { box.removeAttribute('aria-label'); }
    box.removeAttribute('title');
    box.classList.remove('month-dim-hide');
  }

  // ===== helpers de datas =====
  function toISO(brOrIso){
    var s = String(brOrIso||'').trim();
    // dd/mm/aaaa
    var m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/);
    if (m){
      var dd=('0'+parseInt(m[1],10)).slice(-2), mm=('0'+parseInt(m[2],10)).slice(-2);
      return m[3]+'-'+mm+'-'+dd;
    }
    // aaaa-mm-dd
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
    // aaaammdd
    var n = s.match(/^(\d{4})(\d{2})(\d{2})$/);
    if (n) return n[1]+'-'+n[2]+'-'+n[3];
    return '';
  }
  function ymdToInt(iso){ // aaaa-mm-dd -> aaaaMMdd int
    iso = toISO(iso);
    return iso ? parseInt(iso.replace(/-/g,''),10) : null;
  }
  function isoToBr(iso){
    iso = toISO(iso);
    var m=String(iso||'').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return m ? (m[3]+'/'+m[2]+'/'+m[1]) : '—';
  }

  function getCreatedYmd(box){
    return toISO( (box.getAttribute('data-created-ymd')||box.getAttribute('data-de-criacao')||'') );
  }
  function getDueYmd(box){
    return toISO( (box.getAttribute('data-due-ymd')||box.getAttribute('data-de-vencimento')||'') );
  }
  function getPaidYmd(box){
    return toISO( (box.getAttribute('data-paid-ymd')||box.getAttribute('data-de-pagamento')||'') );
  }

  // ===== regra de status histórico =====
function resolveHistoricalStatus(box, refInt){
  var createdI = ymdToInt(getCreatedYmd(box));
  var dueI     = ymdToInt(getDueYmd(box));
  var paidI    = ymdToInt(getPaidYmd(box));

  // 1) não criada
  if (!createdI || createdI > refInt) {
    return { status: 'future', statusLabel: 'Não criada' };
  }

  // 2) paga (pagamento <= ref)
  if (paidI) {
    if (refInt >= paidI) {
      return { status: 'paid', statusLabel: 'Pago' };
    }
  }

  // 3) vencida e não paga (vencimento <= ref)
  if (dueI) {
    if (refInt >= dueI) {
      return { status: 'overdue', statusLabel: 'Em atraso' };
    }
  }

  // 4) criada, antes do vencimento e sem pagamento
  return { status: 'pending', statusLabel: 'Pendente' };
}


  // monta tooltip preservando os dados originais
  function buildTooltip(box, statusLabel, refInt){
    var val   = box.getAttribute('data-valor') || ''; // se você quiser, pode preencher 'data-valor' no PHP
    // pega o valor original do tooltip (se tiver blocos “Valor/Criação/Vencimento/Pagamento”)
    var orig  = box.dataset._origTip || box.getAttribute('data-tooltip') || '';

    // datas “limpas”
    var created = getCreatedYmd(box), due = getDueYmd(box), paid = getPaidYmd(box);
    var refBr = isoToBr(String(refInt));

    var head = 'Status: '+statusLabel+(refBr ? ' ('+refBr+')' : '');

    // Se o tooltip original já traz o bloco de detalhes, mantemos após o cabeçalho
    if (orig && /Valor:|Criação:|Vencimento:|Pagamento:/.test(orig)){
      return head + '\n' + orig.replace(/^Status:[^\n]*\n?/, '');
    }

    // fallback enxuto (se não havia bloco detalhado)
    return head
      + (val   ? '\nValor: '+val : '')
      + (created ? '\nCriação: '+isoToBr(created) : '')
      + (due     ? '\nVencimento: '+isoToBr(due) : '')
      + (paid    ? '\nPagamento: '+isoToBr(paid) : '');
  }

  function applyHistoricalByDate(refYmd){
    var refInt = ymdToInt(refYmd);
    if(!refInt){ clearHistorical(); return; }

    window.__dashHistActive = true;

    var banner = $id('dash-hist-banner'), lab = $id('dash-hist-date-label');
    if(banner) banner.style.display = 'block';
    if(lab)    lab.textContent = isoToBr(refYmd);

    document.querySelectorAll('.dash-table tbody tr[id^="sub-"]').forEach(function(tr){
      var bornCount = 0;
      tr.querySelectorAll('.status-box').forEach(function(box){
        captureOriginalOnce(box);

        var res = resolveHistoricalStatus(box, refInt);
        setStatus(box, res.status);
        var tip = buildTooltip(box, res.statusLabel, refInt);
        box.setAttribute('data-tooltip', tip);
        box.setAttribute('aria-label', tip);
        box.classList.remove('month-dim-hide');

        var createdI = ymdToInt(getCreatedYmd(box));
        if (createdI && createdI <= refInt) bornCount++;
      });

      if (bornCount === 0) {
        tr.classList.add('hist-hide-row');
        tr.style.display = 'none';
      } else {
        tr.classList.remove('hist-hide-row');
        tr.style.display = '';
      }
    });

    if (window.dashRegroupRows) { window.dashRegroupRows(); }
    if (window.updateSummary)   window.updateSummary();
  }

  function clearHistorical(){
    document.querySelectorAll('.dash-table tbody tr[id^="sub-"]').forEach(function(tr){
      tr.classList.remove('hist-hide-row');
      tr.style.display='';
      tr.querySelectorAll('.status-box').forEach(function(box){
        restoreOriginal(box);
      });
    });
    var banner = $id('dash-hist-banner'); if(banner) banner.style.display='none';
    window.__dashHistActive = false;

    if (window.dashRegroupRows) { window.dashRegroupRows(); }
    if (window.updateSummary)   window.updateSummary();
  }

  // expõe para teste manual no console:
  window.__applyHistoricalByDate = applyHistoricalByDate;
  window.__clearHistorical = clearHistorical;

function initAutoHistorical(){
  // Intencionalmente vazio: não auto-aplica histórico ao digitar ou mudar o campo
  // A aplicação do histórico agora será feita SOMENTE pelos botões:
  // - #dash-snapshot-apply (aplicar)
  // - #dash-snapshot-clear (reset total)
}

  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', initAutoHistorical, {once:true});
  } else {
    initAutoHistorical();
  }
})();
</script>

<script>
/* Ligações explícitas dos botões do histórico (sem auto-aplicar no input) */
(function(){
  function $id(id){ return document.getElementById(id); }

  function clearMonthPickerHard(){
    var mon = $id('dash-month-picker');
    if (!mon) return;
    mon.value = '';

    // se jQuery UI Datepicker estiver presente, limpa o estado interno também
    if (typeof jQuery !== 'undefined') {
      var $mon = jQuery('#dash-month-picker');
      if ($mon.length && $mon.data('datepicker')) {
        $mon.datepicker('setDate', null);
        $mon.val('');
      }
    }

    // dispara eventos que o filtro já escuta (garante saída total do filtro de mês)
    mon.dispatchEvent(new Event('dash:monthChanged'));
    mon.dispatchEvent(new Event('change'));
    mon.dispatchEvent(new Event('input'));
  }

  function bindHistoryButtons(){
    var btnApply = $id('dash-snapshot-apply');
    var btnClear = $id('dash-snapshot-clear');
    var input    = $id('dash-snapshot-date');

    // Botão "Histórico" = aplicar histórico SOMENTE quando clicar
    if (btnApply) {
      btnApply.addEventListener('click', function(e){
        e.preventDefault();
        var v = (input && input.value || '').trim();
        if (!v) {
          // sem data => não faz nada (ou, se preferir, poderíamos limpar histórico aqui)
          return;
        }
        if (typeof window.__applyHistoricalByDate === 'function') {
          window.__applyHistoricalByDate(v);
        }
      });
    }

    // Botão "Sair do histórico" = reset TOTAL (hard reset opcional)
if (btnClear) {
  btnClear.addEventListener('click', function(e){
    e.preventDefault();

    // 0) se quiser *garantir* F5 de verdade, ative esta flag
    var HARD_RESET_ON_EXIT = false; // mude para true se quiser recarregar a página

    // 1) desligar histórico *antes* de qualquer reaplicação
    if (typeof window.__clearHistorical === 'function') {
      window.__clearHistorical(); // agora não reaplica filtro internamente
    }

    // 2) limpar todos os inputs (histórico, mês, busca, curso)
    if (input) input.value = ''; // data do histórico

    // mês (inclui limpar o estado interno do jQuery UI datepicker)
    (function clearMonthPickerHard(){
      var mon = $id('dash-month-picker');
      if (!mon) return;
      mon.value = '';
      if (typeof jQuery !== 'undefined') {
        var $mon = jQuery('#dash-month-picker');
        if ($mon.length && $mon.data('datepicker')) {
          $mon.datepicker('setDate', null);
          $mon.val('');
        }
      }
      mon.dispatchEvent(new Event('dash:monthChanged'));
      mon.dispatchEvent(new Event('change'));
      mon.dispatchEvent(new Event('input'));
    })();

    var inp = $id('dash-search-input'); if (inp) inp.value = '';
    var sel = $id('dash-course-filter'); if (sel) sel.value = '';

    // 3) restaurar visual (linhas/boxes) e KPIs
    if (typeof window.dashRegroupRows === 'function') window.dashRegroupRows();
    if (typeof window.updateSummary   === 'function') window.updateSummary();

    // 4) aplicar filtro final com tudo zerado
    if (typeof window.dashApplyFilter === 'function') window.dashApplyFilter();

    // 5) HARD RESET (opcional): simula F5
    if (HARD_RESET_ON_EXIT) {
      window.location.reload();
    }
  });
}

  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindHistoryButtons, { once: true });
  } else {
    bindHistoryButtons();
  }
})();
</script>

<script>
(function(){
  function $id(id){ return document.getElementById(id); }

  // Defina a menor e a maior data permitidas
  var HIST_MIN = '2025-02-19';
  var HIST_MAX = '<?php echo esc_js( date_i18n("Y-m-d", current_time("timestamp")) ); ?>';

  function clampDate(v){
    if (!v) return v;
    if (v < HIST_MIN) return HIST_MIN;
    if (v > HIST_MAX) return HIST_MAX;
    return v;
  }

  function initHistoryDateBounds(){
    var inp = $id('dash-snapshot-date');
    if (!inp) return;

    // Seta os limites nativos do input date
    inp.setAttribute('min', HIST_MIN);
    inp.setAttribute('max', HIST_MAX);

    // Sempre que o usuário mexer, garante que fica na faixa
    inp.addEventListener('input', function(){
      var v = clampDate(inp.value);
      if (v !== inp.value) inp.value = v;
    });
    inp.addEventListener('change', function(){
      var v = clampDate(inp.value);
      if (v !== inp.value) inp.value = v;
    });

    // Se por algum motivo houver valor inicial inválido, corrige
    if (inp.value) {
      var v0 = clampDate(inp.value);
      if (v0 !== inp.value) inp.value = v0;
    }

    // Expõe para outros scripts (opcional)
    window.__histMinYmd = HIST_MIN;
    window.__histMaxYmd = HIST_MAX;
    window.__clampHistYmd = clampDate;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initHistoryDateBounds, { once:true });
  } else {
    initHistoryDateBounds();
  }
})();
</script>



<?php
$html .= ob_get_clean();

    return $html;
}
add_shortcode('dashboard_assinantes_e_pedidos','mostrar_dashboard_assinantes');