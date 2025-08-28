<?php
/*
Plugin Name: Meu Plugin Dashboard Assinantes
Description: Exibe assinaturas Asaas agrupadas por status (Em Dia, Em Atraso e Canceladas) via shortcode [dashboard_assinantes_e_pedidos]
Version: 1.18
Author: Luis Furtado (otimizado)
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
    $subscription_ids = array_values(array_filter(array_map('absint', $subscription_ids))); // sanitize
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
 * Retorna [ subscriptionID(string) => [ rows... ] ], filtrando REFUNDED.
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
            // pula reembolsados
            $st = strtolower(trim($r['status'] ?? ''));
            if ($st === 'refunded') continue;
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
                if ($len !== null && $len !== '') {
                    $length = (int) $len;
                }
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
            if (preg_match('/\\b(\\d{1,2})\\s*mes(?:es)?\\b/i', $blob, $m)) {
                $length = (int) $m[1];
            }
            if ($length === null && preg_match('/\\b(\\d{1,2})\\s*x\\b/i', $blob, $m2)) {
                $length = (int) $m2[1];
            }
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
    // [dashboard_assinantes_e_pedidos id="3815"] (filtra por produto ou seu pai)
    $atts = shortcode_atts(['id' => 0], $atts, 'dashboard_assinantes_e_pedidos');
    $filter_id      = absint($atts['id']);
    $filter_base_id = $filter_id ? dash_maybe_get_parent_product_id($filter_id) : 0;

    $subs  = dash_fetch_all_asaas_subscriptions();
    $agora = time();

    // Coleta IDs para prefetch
    $subscription_table_ids = [];
    $subscriptionIDs        = [];
    foreach ($subs as $s) {
        $subscription_table_ids[] = (int) ($s['id'] ?? 0);               // pk da sua tabela
        $subscriptionIDs[]        = (string) ($s['subscriptionID'] ?? ''); // ID textual usado em pagamentos
    }

    // Mapeamentos em lote (menos queries)
    $pid_map = dash_get_product_map_for_subscriptions($subscription_table_ids);
    $pay_map = dash_fetch_payments_map(array_filter($subscriptionIDs));

    $expiredStatuses = ['CANCELLED','CANCELED','EXPIRED'];
    $overdueStatuses = ['OVERDUE','INACTIVE'];
    $mapPaid         = ['PAYED','PAID','RECEIVED','RECEIVED_IN_CASH','CONFIRMED'];

    $grupos = [ 'em_dia' => [], 'em_atraso' => [], 'canceladas' => [] ];

    foreach ($subs as $sub) {
        $subscription_pk_id = (int) ($sub['id'] ?? 0);
        $subscriptionID     = (string) ($sub['subscriptionID'] ?? '');

        // product_id realmente vinculado a esta assinatura
        $pid_for_select = (int) ($pid_map[$subscription_pk_id] ?? 0);

        // FILTRO PELO ID DO CURSO (via shortcode)
        if ($filter_id) {
            $pid_base = dash_maybe_get_parent_product_id($pid_for_select);
            if ((int)$pid_for_select !== (int)$filter_id && (int)$pid_base !== (int)$filter_base_id) {
                continue;
            }
        }

        $stat_raw = strtoupper(trim($sub['status'] ?? ''));

        // Pagamentos (já filtrados sem REFUNDED)
        $payments = array_values($pay_map[$subscriptionID] ?? []);

        // se só tinha refund (ou nenhum válido), e havia registros brutos, some
        if ((isset($pay_map[$subscriptionID]) && empty($payments))) {
            continue;
        }

        if (in_array($stat_raw, $expiredStatuses, true)) {
            $grupos['canceladas'][] = ['sub' => $sub, 'payments' => $payments, 'product_id' => $pid_for_select];
            continue;
        }

        // Detecta atraso por status geral ou por parcelas vencidas
        $hasOverdue = in_array($stat_raw, $overdueStatuses, true);
        if (!$hasOverdue && $payments) {
            foreach ($payments as $p) {
                $p_stat = strtoupper($p['paymentStatus'] ?? '');
                $due    = strtotime($p['dueDate'] ?? '');
                if (!in_array($p_stat, $mapPaid, true) && (in_array($p_stat, ['OVERDUE','INACTIVE'], true) || ($due && $due < $agora))) {
                    $hasOverdue = true; break;
                }
            }
        }

        $key = $hasOverdue ? 'em_atraso' : 'em_dia';
        $grupos[$key][] = ['sub' => $sub, 'payments' => $payments, 'product_id' => $pid_for_select];
    }

    // ===== CSS (mais leve) =====
$css .= '<style>
:root{ --dash-primary:#0a66c2; --dash-primary-dark:#084a8f; --dash-text:#1f2937; --dash-muted:#6b7280; --dash-border:#e5e7eb; }
.dash-wrap{font-family:Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji"; color:var(--dash-text)}
.dash-h3{margin:24px 0 8px; font-size:1.125rem; font-weight:700}
/* bordas mais grossas e sem cortar tooltip */
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
.status-box{position:relative; display:inline-block; width:14px; height:14px; margin:0 2px; border-radius:3px; border:1px solid rgba(0, 0, 0, 0.35); transition: transform .14s ease, box-shadow .14s ease, border-color .14s ease, filter .14s ease; cursor: default; z-index:1;}
.status-box:hover{transform: translateY(-1px) scale(1.18); box-shadow: 0 6px 16px rgba(0,0,0,.18); border-color: rgba(0, 0, 0, 0.5);}
.status-box.paid{ background:#22c55e; box-shadow: inset 0 0 0 1px rgba(0,0,0,.06); }
.status-box.paid:hover{ filter: saturate(1.15) brightness(1.05); }
.status-box.overdue{ background:#ef4444; }
.status-box.future{ background:transparent; }
.status-box[data-tooltip]:hover::after{content:attr(data-tooltip); position:absolute; bottom: calc(100% + 8px); left:50%; transform:translateX(-50%); background:rgba(17,24,39,.96); color:#fff; padding:8px 10px; border-radius:6px; white-space:pre; font-size:.8em; line-height:1.3; max-width:20rem; text-align:left; z-index:9999; pointer-events:none;}
.status-box[data-tooltip]:hover::before{content:""; position:absolute; bottom:100%; left:50%; transform:translateX(-50%); border:6px solid transparent; border-top-color:rgba(17,24,39,.96);}
.debug-info{display:none !important;}
</style>';



    // ===== Busca (nome/email/CPF) =====
    $search = '<div class="dash-wrap"><div class="dash-search" role="search" aria-label="Filtrar assinantes">
        <input type="text" id="dash-search-input" class="dash-input" placeholder="Buscar por nome, e-mail ou CPF" aria-label="Buscar por nome, e-mail ou CPF">
        <button type="button" id="dash-search-btn" class="dash-btn">Buscar</button>
    </div>';

    // ===== Tabela builder =====
    $build_row = function(array $sub, array $payments, int $product_id) use ($agora) {
        $subscription_pk_id = (int) ($sub['id'] ?? 0);
        $base_id            = dash_maybe_get_parent_product_id($product_id);

        // Total de parcelas (estimado)
        $totalInstallments = 6; // fallback
        $cycles = null;
        if ($product_id) {
            $cycles = dash_get_subscription_cycles_from_product($product_id);
            if ($cycles !== null) {
                $totalInstallments = ($cycles > 0) ? (int) $cycles : max(count($payments), 6);
            }
        }
        if ((!$product_id || $cycles === null) && !empty($sub['order_id'])) {
            $maybe_cycles = dash_get_cycles_from_wc_subscription_order($sub['order_id']);
            if ($maybe_cycles !== null) {
                $totalInstallments = ($maybe_cycles > 0) ? (int) $maybe_cycles : max(count($payments), 6);
            }
        }
        if (count($payments) > $totalInstallments) $totalInstallments = count($payments);

        // Mapeia parcelas às posições
        $installments = array_fill(0, $totalInstallments, null);
        foreach ($payments as $p) {
            $idx = (isset($p['installmentNumber']) && is_numeric($p['installmentNumber'])) ? ((int)$p['installmentNumber'] - 1) : null;
            if ($idx === null || $idx < 0 || $idx >= $totalInstallments) {
                $idx = array_search(null, $installments, true);
            }
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
            $cls = 'future'; $tooltip = 'Parcela não criada';
            if ($p) {
                $val   = isset($p['value']) ? number_format((float)$p['value'], 2, ',', '.') : '0,00';
                $creAt = $p['created'] ?? ($p['createdAt'] ?? '');
                $created = $creAt ? date_i18n('d/m/Y - H:i', strtotime($creAt)) : '—';
                $p_stat  = strtoupper($p['paymentStatus'] ?? '');
                $due_ts  = strtotime($p['dueDate'] ?? '');

                $isPaid    = in_array($p_stat, ['PAYED','PAID','RECEIVED','RECEIVED_IN_CASH','CONFIRMED'], true);
                $isOverdue = (!$isPaid && ($due_ts && $due_ts < $agora || in_array($p_stat, ['OVERDUE','INACTIVE'], true)));

                if ($isPaid) { $cls='paid'; $pag=date_i18n('d/m/Y', strtotime($p['paymentDate'] ?? '')); }
                elseif ($isOverdue) { $cls='overdue'; $pag='ATRASADA'; }
                else { $cls='future'; $pag='—'; }

                $tooltip = "Valor: R$ {$val}\nCriação: {$created}\nPagamento: {$pag}";
            }
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

    $html = $css . $search;

    foreach (['em_atraso' => 'Em Atraso', 'em_dia' => 'Em Dia', 'canceladas' => 'Canceladas'] as $key => $titulo) {
        $html .= '<h3 class="dash-h3">Assinaturas — ' . esc_html($titulo) . '</h3>';
        $html .= "<table class='dash-table' role='table' aria-label='Assinaturas {$titulo}'><thead><tr><th>Nome</th><th>E-mail</th><th>CPF</th><th>Pagamentos</th><th>Status</th></tr></thead><tbody>";
        if (empty($grupos[$key])) {
            $html .= "<tr><td colspan='5'><em>Nenhuma assinatura nesta categoria.</em></td></tr>";
        } else {
            foreach ($grupos[$key] as $entry) {
                $html .= $build_row($entry['sub'], $entry['payments'], (int)$entry['product_id']);
            }
        }
        $html .= '</tbody></table>';
    }

    // ===== JS: filtro em tempo real + botão =====
    $html .= "<script>
(function(){
  function normalize(s){
    return (s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
  }
  function doFilter(){
    var q = normalize(document.getElementById('dash-search-input').value.trim());
    document.querySelectorAll('.dash-table tbody tr').forEach(function(tr){
      var c = tr.cells; if(!c||c.length<3){ tr.style.display=''; return; }
      var nome  = normalize(c[0].textContent);
      var email = normalize(c[1].textContent);
      var cpf   = normalize(c[2].textContent);

      /* também permite buscar pelo id do <tr> e pelos data-attributes */
      var rid   = (tr.id||'').toLowerCase();
      var cid   = String(tr.getAttribute('data-course-id')||'').toLowerCase();
      var cbase = String(tr.getAttribute('data-course-base-id')||'').toLowerCase();

      tr.style.display = (
        q=== '' ||
        nome.includes(q) || email.includes(q) || cpf.includes(q) ||
        rid.includes(q) || cid.includes(q) || cbase.includes(q)
      ) ? '' : 'none';
    });
  }

  /* >>> SOMENTE pelo botão (sem filtrar ao digitar/Enter) */
  var btn = document.getElementById('dash-search-btn');
  btn.addEventListener('click', doFilter);
})();
</script>
</div>";

    return $html;
}
add_shortcode('dashboard_assinantes_e_pedidos', 'mostrar_dashboard_assinantes');
