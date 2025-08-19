<?php
/*
Plugin Name: Meu Plugin Dashboard Assinantes
Description: Exibe assinaturas Asaas agrupadas por status (Em Dia, Em Atraso e Canceladas) via shortcode [dashboard_assinantes_e_pedidos]
Version: 1.17
Author: Luis Furtado
*/

defined( 'ABSPATH' ) || exit;

/**
 * 1) Busca todas as assinaturas + nome/email do WP User
 */
function fetch_all_asaas_subscriptions() {
    global $wpdb;

    $subs_table  = $wpdb->prefix . 'processa_pagamentos_asaas_subscriptions';
    $users_table = $wpdb->users;

    $sql = "
      SELECT 
        s.*,
        u.display_name   AS customer_name,
        u.user_email     AS customer_email
      FROM {$subs_table} s
      LEFT JOIN {$users_table} u 
        ON u.ID = s.userID
      ORDER BY s.id DESC
    ";

    return $wpdb->get_results( $sql, ARRAY_A );
}

/**
 * 2) Busca todas as parcelas de assinatura na tabela de pagamentos
 *    Ordenadas pelas datas mais antigas primeiro
 */
function fetch_asaas_subscription_payments( $subscriptionID ) {
    global $wpdb;
    $payments_table = $wpdb->prefix . 'processa_pagamentos_asaas';

    $sql = $wpdb->prepare(
        "
        SELECT *
        FROM {$payments_table}
        WHERE `type` = %s
          AND orderID = %s
          
        ORDER BY dueDate ASC, created ASC
        ",
        'assinatura',
        $subscriptionID
    );

    return $wpdb->get_results( $sql, ARRAY_A );
}


/** ========= HELPERS: produto → nº de ciclos ========= */

/**
 * Busca o product_id em wp_processa_pagamentos_asaas_subscriptions_items
 * usando o ID da assinatura (s.id) na coluna subscription_table_id.
 */
function get_product_id_for_subscription_local( $subscription_table_id ) {
    if ( empty( $subscription_table_id ) ) return 0;

    static $cache = [];
    if ( isset( $cache[ $subscription_table_id ] ) ) {
        return $cache[ $subscription_table_id ];
    }

    global $wpdb;
    $items_table = $wpdb->prefix . 'processa_pagamentos_asaas_subscriptions_items';

    // *** AQUI O AJUSTE PRINCIPAL ***
    $product_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT product_id FROM {$items_table} WHERE subscription_table_id = %d LIMIT 1",
        (int) $subscription_table_id
    ) );

    $cache[ $subscription_table_id ] = $product_id;
    return $product_id;
}

/** Se o product_id for variação, devolve o ID do produto pai */
function maybe_get_parent_product_id_local( $product_id ) {
    if ( ! function_exists( 'wc_get_product' ) || ! $product_id ) return (int) $product_id;
    $p = wc_get_product( $product_id );
    if ( $p && $p->is_type( 'variation' ) ) {
        $parent_id = $p->get_parent_id();
        return $parent_id ? (int) $parent_id : (int) $product_id;
    }
    return (int) $product_id;
}

/**
 * Obtém nº de ciclos/meses do produto de assinatura no WooCommerce.
 * Retorna:
 *   > 0  => nº de ciclos definido
 *   = 0  => sem término
 *   null => desconhecido
 */
function get_subscription_cycles_from_product( $product_id ) {
    if ( ! $product_id ) return null;

    static $cache = [];
    if ( isset( $cache[ $product_id ] ) ) return $cache[ $product_id ];

    $length = null;
    $base_id = maybe_get_parent_product_id_local( $product_id );

    // 1) Woo Subscriptions (se ativo)
    if ( function_exists( 'wc_get_product' ) && class_exists( 'WC_Subscriptions_Product' ) ) {
        try {
            $product_obj = wc_get_product( $base_id );
            if ( $product_obj ) {
                $len = \WC_Subscriptions_Product::get_length( $product_obj ); // 0=open-ended, >0=nº ciclos
                if ( $len !== null && $len !== '' ) $length = (int) $len;
            }
        } catch ( \Throwable $e ) { /* fallback abaixo */ }
    }

    // 2) Metas comuns
    if ( $length === null ) {
        $meta_keys = [ '_subscription_length', '_billing_cycles', 'subscription_length', 'assinatura_meses', 'assinatura_parcelas' ];
        foreach ( $meta_keys as $k ) {
            $v = get_post_meta( $base_id, $k, true );
            if ( $v !== '' && $v !== null ) { $length = (int) $v; break; }
        }
    }

    // 3) Regex em título/descrição (ex.: “6 meses”, “12x”)
    if ( $length === null && function_exists( 'wc_get_product' ) ) {
        $p = wc_get_product( $base_id );
        if ( $p ) {
            $blob = ' ' . $p->get_name() . ' ';
            if ( method_exists( $p, 'get_short_description' ) ) $blob .= ' ' . $p->get_short_description();
            if ( method_exists( $p, 'get_description' ) )       $blob .= ' ' . $p->get_description();
            if ( preg_match( '/\b(\d{1,2})\s*mes(?:es)?\b/i', $blob, $m ) ) $length = (int) $m[1];
            if ( $length === null && preg_match( '/\b(\d{1,2})\s*x\b/i', $blob, $m2 ) ) $length = (int) $m2[1];
        }
    }

    $cache[ $product_id ] = $length;
    return $length;
}

/** (Opcional) Infere nº de ciclos a partir de uma WC_Subscription ligada ao order_id */
function get_cycles_from_wc_subscription_order( $order_id ) {
    if ( empty( $order_id ) ) return null;
    if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) || ! function_exists( 'wcs_estimate_periods_between' ) ) return null;

    $subs_wc = wcs_get_subscriptions_for_order( $order_id, [ 'order_type' => 'parent' ] );
    if ( empty( $subs_wc ) || ! is_array( $subs_wc ) ) return null;

    $wc_sub = reset( $subs_wc );
    if ( ! $wc_sub || ! is_a( $wc_sub, 'WC_Subscription' ) ) return null;

    $end    = (int) $wc_sub->get_time( 'end' );      // 0 => sem fim
    $period = $wc_sub->get_billing_period();         // 'day','week','month','year'
    if ( $end <= 0 || empty( $period ) ) return 0;

    $trial_end   = (int) $wc_sub->get_time( 'trial_end' );
    if ( class_exists( 'WC_Subscriptions_Synchroniser' ) && method_exists( 'WC_Subscriptions_Synchroniser', 'subscription_contains_synced_product' ) &&
         \WC_Subscriptions_Synchroniser::subscription_contains_synced_product( $wc_sub->get_id() ) ) {
        $length_from = (int) $wc_sub->get_time( 'next_payment' );
    } else {
        $length_from = $trial_end > 0 ? $trial_end : (int) $wc_sub->get_time( 'start' );
    }

    $cycles = (int) wcs_estimate_periods_between( $length_from, $end, $period );
    return ( $cycles >= 0 ) ? $cycles : null;
}

/**
 * 3) Monta o HTML do dashboard
 */
function mostrar_dashboard_assinantes() {
    $subs  = fetch_all_asaas_subscriptions();
    $agora = time();
    $grupos = [
      'em_dia'     => [],
      'em_atraso'  => [],
      'canceladas' => [],
    ];

    $expiredStatuses = ['CANCELLED','CANCELED','EXPIRED'];
    $overdueStatuses = ['OVERDUE','INACTIVE'];

    foreach ( $subs as $sub ) {
        $stat_raw = strtoupper( trim( $sub['status'] ?? '' ) );
        $payments = fetch_asaas_subscription_payments( $sub['subscriptionID'] ?? '' );
        // Ignora parcelas reembolsadas (status = refunded)
        $payments = array_values(array_filter($payments, function($p){
            return strtolower(trim($p['status'] ?? '')) !== 'refunded';
        }));


        if ( in_array( $stat_raw, $expiredStatuses, true ) ) {
            $grupos['canceladas'][] = compact( 'sub', 'payments' );
            continue;
        }

        $hasOverdue = in_array( $stat_raw, $overdueStatuses, true );
        if ( ! $hasOverdue ) {
            foreach ( $payments as $p ) {
                $p_stat = strtoupper( $p['paymentStatus'] ?? '' );
                $due    = strtotime( $p['dueDate'] ?? '' );
                $mapPaid = ['PAYED','PAID','RECEIVED','RECEIVED_IN_CASH','CONFIRMED'];
                if ( ! in_array( $p_stat, $mapPaid, true ) && ( in_array( $p_stat, ['OVERDUE','INACTIVE'], true ) || ( $due && $due < $agora ) ) ) {
                    $hasOverdue = true; break;
                }
            }
        }

        $key = $hasOverdue ? 'em_atraso' : 'em_dia';
        $grupos[ $key ][] = compact( 'sub', 'payments' );
    }

    // CSS
    $html = '<style>
  .dash-table { width:100%; border-collapse:collapse; margin-bottom:2em; font-family:"Helvetica Neue",Arial,sans-serif; font-size:0.95em; color:#333; box-shadow:0 2px 6px rgba(0,0,0,0.1); border-radius:6px; overflow:hidden; }
  .dash-table th, .dash-table td { padding:12px 8px; text-align:center; border:2px solid #777; }
  .dash-table thead th { background:#005b96; color:#fff; text-transform:uppercase; font-weight:600; border-bottom:3px solid #333; }
  .dash-table tbody tr:nth-child(even) { background:#f9f9f9; }
  .dash-table tbody tr:hover { background:#eef6fc; }
  .dash-table td:nth-child(4) { min-width:260px; white-space:nowrap; }
  .status-box { position:relative; display:inline-block; width:16px; height:16px; margin-right:4px; border-radius:3px; vertical-align:middle; border:1px solid transparent; transition:transform .2s; }
  .status-box:hover { transform:scale(1.2); }
  .status-box.paid { background:#28a745; border-color:#1e7e34; }
  .status-box.overdue { background:#dc3545; border-color:#b21f2d; }
  .status-box.future { background:transparent; border-color:#6c757d; }
  .status-box[data-tooltip]:hover::after { content:attr(data-tooltip); position:absolute; bottom:120%; left:50%; transform:translateX(-50%); background:rgba(0,0,0,0.75); color:#fff; padding:6px 8px; border-radius:4px; white-space:pre-line; font-size:0.8em; z-index:10; pointer-events:none; width: 13rem; text-align: start; }
  .debug-info { display:block; margin-bottom:6px; font-size:0.8em; color:#555; text-align:left; white-space:normal; }
  #search-button {background-color: #005b96;color: white;border: none;cursor: pointer;font-size: 16px;}
  #search-button:hover {background-color: #003f7c;}
  .debug-info strong { font-size: 1em; color: #333; margin-top: 8px; }
</style>';

    // Busca
    $html .= '<div style="width:100%;">
    <input type="text" id="search-assinantes"
  placeholder="Buscar por nome, e-mail, CPF ou ID do curso (#123 ou id:123)…"
  style="margin-bottom:12px;padding:6px;width:100%;max-width:400px;border-radius: 5px;border: 1px solid;">
    <button id="search-button" style="padding:6px 12px; margin-top: 10px; border-radius: 5px;">Buscar</button>
</div>';

    $html .= "<script>
document.addEventListener('DOMContentLoaded', function() {
  const input = document.getElementById('search-assinantes');
  const button = document.getElementById('search-button');
  function normalizeString(str){ return str.normalize('NFD').replace(/[\\u0300-\\u036f]/g,'').toLowerCase(); }
  function normalizeCPF(cpf){ return cpf.replace(/[^\\d]/g,''); }
  function doFilter(){
    const filter = normalizeString(input.value.trim());
    document.querySelectorAll('.dash-table').forEach(table=>{
      const tbody = table.tBodies[0];
      const rows = tbody ? Array.from(tbody.rows) : [];
      rows.forEach(row=>{
        const c=row.cells; if(!c||c.length<3) return;
        const nome=normalizeString(c[0].textContent||'');
        const email=normalizeString(c[1].textContent||'');
        const cpf=normalizeCPF((c[2].textContent||'').toLowerCase());
        row.style.display=(nome.includes(filter)||email.includes(filter)||cpf.includes(filter))?'':'none';
      });
    });
  }
  button.addEventListener('click', doFilter);
  input.addEventListener('keydown', e=>{ if(e.key==='Enter') doFilter(); });
});
</script>";

    // Render de linhas
    $render_row = function( $sub, $payments ) use ( $agora ) {

        // ===== número de boxes =====
        $totalInstallments = 6; // fallback final

        // 1) product_id via items.subscription_table_id = s.id
        $subscription_pk_id = (int) ( $sub['id'] ?? 0 );
        $product_id         = get_product_id_for_subscription_local( $subscription_pk_id );

        $course_base_id     = maybe_get_parent_product_id_local( $product_id );

        // 2) tenta pelo produto (preferencial)
        $cycles = null;
        if ( $product_id ) {
            $cycles = get_subscription_cycles_from_product( $product_id );
            if ( $cycles !== null ) {
                if ( $cycles > 0 ) $totalInstallments = (int) $cycles;
                elseif ( $cycles === 0 ) $totalInstallments = max( count( $payments ), 6 );
            }
        }

        // 3) se não achou, tenta WC_Subscription via order_id
        if ( ( ! $product_id || $cycles === null ) && ! empty( $sub['order_id'] ) ) {
            $maybe_cycles = get_cycles_from_wc_subscription_order( $sub['order_id'] );
            if ( $maybe_cycles !== null ) {
                if ( $maybe_cycles > 0 ) $totalInstallments = (int) $maybe_cycles;
                elseif ( $maybe_cycles === 0 ) $totalInstallments = max( count( $payments ), 6 );
            }
        }

        // 4) nunca menos que o número de pagamentos já criados
        if ( count( $payments ) > $totalInstallments ) $totalInstallments = count( $payments );

        // ===== mapeia parcelas =====
        $installments = array_fill(0, $totalInstallments, null);
        foreach ( $payments as $p ) {
            $idx = isset( $p['installmentNumber'] ) && is_numeric( $p['installmentNumber'] ) ? ((int)$p['installmentNumber'] - 1) : null;
            if ( $idx === null || $idx < 0 || $idx >= $totalInstallments ) $idx = array_search( null, $installments, true );
            if ( $idx !== false ) $installments[ $idx ] = $p;
        }

        // contagem por status
        $countPaid = $countOverdue = 0;
        $mapPaid = ['PAYED','PAID','RECEIVED','RECEIVED_IN_CASH','CONFIRMED'];
        foreach ( $installments as $p ) {
            if ( $p ) {
                $p_stat = strtoupper( $p['paymentStatus'] ?? '' );
                $due    = strtotime( $p['dueDate'] ?? '' );
                if ( in_array( $p_stat, $mapPaid, true ) ) $countPaid++;
                elseif ( in_array( $p_stat, ['OVERDUE','INACTIVE'], true ) || ( $due && $due < $agora ) ) $countOverdue++;
            }
        }
        $countFuture = max( 0, $totalInstallments - $countPaid - $countOverdue );

        // render
        $r  = '<tr data-course-id="'. esc_attr( $product_id ?: '' ) .'" data-course-base-id="'. esc_attr( $course_base_id ?: '' ) .'">';
        $r .= '<td>'. esc_html($sub['customer_name'] ?? '—') .
            '<span style="display:none"> id:' . (int)$product_id . ' #' . (int)$product_id . ' ' . (int)$product_id . '</span></td>';
        $r .= '<td>'. esc_html( $sub['customer_email'] ?? '—' ) .'</td>';
        $r .= '<td>'. esc_html( $sub['cpf'] ?? '—' ) .'</td>';
        $r .= '<td>';
        $r .= '<div class="debug-info"><strong>Assinatura (s.id):</strong> '. ( $subscription_pk_id ?: '—' ) .'</div>';
        $r .= '<div class="debug-info"><strong>Produto (ID):</strong> '. ( $product_id ?: '—' ) .'</div>';
        $r .= '<div class="debug-info"><strong>Boxes:</strong> '. intval($totalInstallments) .'</div>';
        $r .= "<div class='debug-info'>{$countPaid} pagas<br>{$countOverdue} atrasadas<br>{$countFuture} futuras</div>";

        foreach ( $installments as $i => $p ) {
            if ( $p ) {
                $val         = isset( $p['value'] ) ? number_format( (float)$p['value'], 2, ',', '.' ) : '0,00';
                $created_raw = $p['created'] ?? ( $p['createdAt'] ?? '' );
                $created     = $created_raw ? date_i18n('d/m/Y - H:i', strtotime($created_raw)) : '—';
                $p_stat      = strtoupper( $p['paymentStatus'] ?? '' );
                $due_ts      = strtotime( $p['dueDate'] ?? '' );
                $mapPaid     = ['PAYED','PAID','RECEIVED','RECEIVED_IN_CASH','CONFIRMED'];

                $isPaid    = in_array( $p_stat, $mapPaid, true );
                $isOverdue = ( in_array( $p_stat, ['OVERDUE','INACTIVE'], true ) || ( $due_ts && $due_ts < time() && ! $isPaid ) );

                if ( $isPaid )      { $pag = date_i18n('d/m/Y', strtotime($p['paymentDate'] ?? '')); $cls = 'paid'; }
                elseif ( $isOverdue ){ $pag = 'ATRASADA'; $cls = 'overdue'; }
                else                 { $pag = '—'; $cls = 'future'; }

                $tooltip = "Valor: R$ {$val}\nCriação: {$created}\nPagamento: {$pag}";
            } else {
                $tooltip = 'Parcela não criada';
                $cls     = 'future';
            }
            $r .= '<span class="status-box '. esc_attr($cls) .'" data-tooltip="'. esc_attr($tooltip) .'"></span>';
        }
        $r .= '</td>';

        // status geral
        $r .= '<td>';
        $stat = strtoupper( trim( $sub['status'] ?? '' ) );
        $expiredStatuses = ['CANCELLED','CANCELED','EXPIRED'];
        if ( in_array( $stat, $expiredStatuses, true ) ) {
            $date = $sub['cancelled_at'] ?? ($sub['cancelledAt'] ?? ($sub['canceledAt'] ?? ($sub['expiredAt'] ?? ($sub['updated'] ?? ''))));
            $r .= $date ? 'Cancelado em ' . date_i18n('d/m/Y', strtotime($date)) : 'Cancelado';
        } else {
            $r .= $stat === 'ACTIVE' ? 'Ativo' : ( $stat === 'INACTIVE' ? 'Inativo' : ucfirst(strtolower($sub['status'] ?? '—')) );
        }
        $r .= '</td></tr>';

        return $r;
    };

    foreach ( [ 'em_atraso' => 'Em Atraso', 'em_dia' => 'Em Dia', 'canceladas' => 'Canceladas' ] as $key => $titulo ) {
        $html .= "<h3>Assinaturas — {$titulo}</h3>";
        $html .= "<table class='dash-table'><thead><tr><th>Nome</th><th>E-mail</th><th>CPF</th><th>Pagamentos</th><th>Status</th></tr></thead><tbody>";
        if ( empty( $grupos[$key] ) ) {
            $html .= "<tr><td colspan='5'><em>Nenhuma assinatura nesta categoria.</em></td></tr>";
        } else {
            foreach ( $grupos[$key] as $entry ) {
                $html .= $render_row( $entry['sub'], $entry['payments'] );
            }
        }
        $html .= '</tbody></table>';
    }

    return $html;
}

add_shortcode( 'dashboard_assinantes_e_pedidos', 'mostrar_dashboard_assinantes' );
