<?php
/*
Plugin Name: Meu Plugin Dashboard Assinantes
Description: Exibe assinaturas Asaas agrupadas por status (Em Dia, Em Atraso e Canceladas) via shortcode [dashboard_assinantes_e_pedidos]
Version: 1.13
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
 */
function fetch_asaas_subscription_payments( $subscriptionID, $cpf ) {
    global $wpdb;
    $payments_table = $wpdb->prefix . 'processa_pagamentos_asaas';

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT *
             FROM {$payments_table}
             WHERE `type` = %s
               AND ( orderID = %s OR cpf = %s )
             ORDER BY dueDate ASC",
            'assinatura',
            $subscriptionID,
            $cpf
        ),
        ARRAY_A
    );
}

/**
 * 3) Monta o HTML do dashboard, com tooltips nas parcelas
 */
function mostrar_dashboard_assinantes() {
    $subs   = fetch_all_asaas_subscriptions();
    $agora  = time();
    $grupos = [
      'em_dia'     => [],
      'em_atraso'  => [],
      'canceladas' => [],
    ];

    $expiredStatuses = ['CANCELLED','CANCELED','EXPIRED'];
    $overdueStatuses = ['OVERDUE','INACTIVE'];

    // Classifica em grupos
    foreach ( $subs as $sub ) {
        $stat_raw = strtoupper( trim( $sub['status'] ?? '' ) );
        $payments = fetch_asaas_subscription_payments( $sub['subscriptionID'], $sub['cpf'] );

        if ( in_array( $stat_raw, $expiredStatuses, true ) ) {
            $grupos['canceladas'][] = compact( 'sub', 'payments' );
            continue;
        }

        $hasOverdue = in_array( $stat_raw, $overdueStatuses, true );
        if ( ! $hasOverdue ) {
            foreach ( $payments as $p ) {
                $p_stat = strtoupper( $p['paymentStatus'] ?? '' );
                $due    = strtotime( $p['dueDate'] ?? '' );
                if ( in_array( $p_stat, ['OVERDUE','INACTIVE'], true )
                  || ( $due && $due < $agora && $p_stat !== 'PAYED' ) ) {
                    $hasOverdue = true;
                    break;
                }
            }
        }

        $key = $hasOverdue ? 'em_atraso' : 'em_dia';
        $grupos[ $key ][] = compact( 'sub', 'payments' );
    }

    // CSS com tooltips
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
  .status-box[data-tooltip]:hover::after { content:attr(data-tooltip); position:absolute; bottom:120%; left:50%; transform:translateX(-50%); background:rgba(0,0,0,0.75); color:#fff; padding:6px 8px; border-radius:4px; white-space:pre-line; font-size:0.8em; z-index:10; pointer-events:none; width: 13rem;
    text-align: start; }
  .debug-info { display:block; margin-bottom:6px; font-size:0.8em; color:#555; text-align:left; white-space:normal; }
</style>';

    // Função de renderização de linhas
    $render_row = function( $sub, $payments ) use ( $agora, $expiredStatuses ) {
        $totalInstallments = ! empty( $sub['installments'] ) ? (int) $sub['installments'] : 6;
        $countPaid = $countOverdue = 0;
        foreach ( $payments as $p ) {
            $p_stat = strtoupper( $p['paymentStatus'] ?? '' );
            $due    = strtotime( $p['dueDate'] ?? '' );
            if ( $p_stat === 'PAYED' ) {
                $countPaid++;
            } elseif ( in_array( $p_stat, ['OVERDUE','INACTIVE'], true ) || ( $due && $due < $agora && $p_stat !== 'PAYED' ) ) {
                $countOverdue++;
            }
        }
        $countFuture = max(0, $totalInstallments - $countPaid - $countOverdue);

        $r  = '<tr>';
        $r .= '<td>'. esc_html( $sub['customer_name'] ?? '—' ) .'</td>';
        $r .= '<td>'. esc_html( $sub['customer_email'] ?? '—' ) .'</td>';
        $r .= '<td>'. esc_html( $sub['cpf'] ?? '—' ) .'</td>';
        $r .= '<td>';            
        $r .= "<div class='debug-info'>{$countPaid} pagas<br>{$countOverdue} atrasadas<br>{$countFuture} futuras</div>";

        // loop com tooltip
        for ( $i = 0; $i < $totalInstallments; $i++ ) {
            // define dados
            if ( isset( $payments[ $i ] ) ) {
                $p       = $payments[ $i ];
                $val     = number_format( $p['value'], 2, ',', '.' );
                $created = date_i18n('d/m/Y - H:i', strtotime( $p['created'] ??''));
                $p_stat  = strtoupper($p['paymentStatus'] ?? '');
                $due_ts  = strtotime($p['dueDate'] ?? '');
                if ( $p_stat === 'PAYED' ) {
                    $paid_at = date_i18n('d/m/Y', strtotime($p['paymentDate'] ?? ''));
                    $pag     = $paid_at;
                } elseif ( in_array($p_stat,['OVERDUE','INACTIVE'],true) || ($due_ts && $due_ts < $agora && $p_stat!=='PAYED') ) {
                    $pag = 'ATRASADA';
                } else {
                    $pag = '—';
                }
                $tooltip = "Valor: R$ {$val}\nCriação: {$created}\nPagamento: {$pag}";
            } else {
                $tooltip = 'Parcela não criada';
            }
            // classe de cor
            if ( $i < $countPaid ) {
                $cls = 'paid';
            } elseif ( $i < $countPaid + $countOverdue ) {
                $cls = 'overdue';
            } else {
                $cls = 'future';
            }
            $r .= '<span class="status-box '. esc_attr($cls) .'" data-tooltip="'. esc_attr($tooltip) .'"></span>';
        }

        $r .= '</td>';
        $r .= '<td>';
        $stat = strtoupper(trim($sub['status'] ?? ''));
        if ( in_array($stat, $expiredStatuses, true) ) {
            $date = $sub['cancelled_at'] ?? $sub['cancelledAt'] ?? $sub['canceledAt'] ?? $sub['expiredAt'] ?? $sub['updated'] ?? '';
            $r .= $date ? 'Cancelado em ' . date_i18n('d/m/Y', strtotime($date)) : 'Cancelado';
        } else {
            $r .= ucfirst(strtolower($sub['status'] ?? '—'));
        }
        $r .= '</td></tr>';
        return $r;
    };

    // Monta tabelas por categoria
    foreach ([ 'em_dia'=>'Em Dia','em_atraso'=>'Em Atraso','canceladas'=>'Canceladas'] as $key=>$titulo) {
        $html .= "<h3>Assinaturas — {$titulo}</h3>";
        $html .= "<table class='dash-table'><thead><tr><th>Nome</th><th>E‑mail</th><th>CPF</th><th>Pagamentos</th><th>Status</th></tr></thead><tbody>";
        if ( empty($grupos[$key]) ) {
            $html .= "<tr><td colspan='5'><em>Nenhuma assinatura nesta categoria.</em></td></tr>";
        } else {
            foreach ($grupos[$key] as $entry) {
                $html .= $render_row($entry['sub'],$entry['payments']);
            }
        }
        $html .= '</tbody></table>';
    }

    return $html;
}
add_shortcode('dashboard_assinantes_e_pedidos','mostrar_dashboard_assinantes');

