<?php
/*
Plugin Name: Meu Plugin Dashboard Assinantes
Description: Exibe assinaturas Asaas agrupadas por status (Em Dia, Em Atraso e Canceladas) via shortcode [dashboard_assinantes_e_pedidos]
Version: 1.12
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
 * 3) Monta o HTML do dashboard, usando contadores sequenciais para pintar os quadrados
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

    // categoriza
    foreach ( $subs as $sub ) {
        $stat_raw = strtoupper( trim( $sub['status'] ?? '' ) );
        $payments = fetch_asaas_subscription_payments( $sub['subscriptionID'], $sub['cpf'] );

        if ( in_array( $stat_raw, $expiredStatuses, true ) ) {
            $grupos['canceladas'][] = compact( 'sub', 'payments' );
            continue;
        }

        // cheque de atraso geral via status da assinatura
        $hasOverdue = in_array( $stat_raw, $overdueStatuses, true );

        // depois cheque parcelas vencidas
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

        if ( $hasOverdue ) {
            $grupos['em_atraso'][] = compact( 'sub', 'payments' );
        } else {
            $grupos['em_dia'][] = compact( 'sub', 'payments' );
        }
    }

    // CSS
    $html = '
    <style>
        .dash-table { width:100%; border-collapse:collapse; margin-bottom:2em; }
        .dash-table th, .dash-table td { text-align:center; border:2px solid #000; padding:5px; }
        .status-box { display:inline-block; width:10%; height:50px; margin-right:4px;
                      border:2px solid transparent; vertical-align:middle; }
        .status-box.paid    { background:green; }
        .status-box.overdue { background:red; }
        .status-box.future  { background:transparent; border-color:#000; }
        .debug-info { margin-top:4px; font-size:0.85em; color:#333; text-align:left; }
    </style>
    ';

    // renderer: pinta primeiro os pagos, depois os atrasados, o restante futuros
    $render_row = function( $sub, $payments ) use ( $agora ) {
        $totalInstallments = ! empty( $sub['installments'] )
                            ? (int) $sub['installments']
                            : 6;

        // conta pagos e atrasados
        $countPaid    = 0;
        $countOverdue = 0;
        foreach ( $payments as $p ) {
            $p_stat = strtoupper( $p['paymentStatus'] ?? '' );
            $due    = strtotime( $p['dueDate'] ?? '' );

            if ( $p_stat === 'PAYED' ) {
                $countPaid++;
            } elseif ( in_array( $p_stat, ['OVERDUE','INACTIVE'], true )
                    || ( $due && $due < $agora && $p_stat !== 'PAYED' ) ) {
                $countOverdue++;
            }
        }
        $countFuture = max(0, $totalInstallments - $countPaid - $countOverdue);

        $r  = '<tr>';
        $r .= '<td>'. esc_html( $sub['customer_name']  ?? '—' ) .'</td>';
        $r .= '<td>'. esc_html( $sub['customer_email'] ?? '—' ) .'</td>';
        $r .= '<td>'. esc_html( $sub['cpf']            ?? '—' ) .'</td>';

        $r .= '<td>';
        $r .= "<div class='debug-info'>{$countPaid} pagas <br>
         {$countOverdue} atrasadas <br>
          {$countFuture} futuras</div>";

        // pinta os quadrados em sequência
        for ( $i = 0; $i < $totalInstallments; $i++ ) {
            if ( $i < $countPaid ) {
                $cls = 'paid';
            } elseif ( $i < $countPaid + $countOverdue ) {
                $cls = 'overdue';
            } else {
                $cls = 'future';
            }
            $r .= "<span class='status-box {$cls}'></span>";
        }
        $r .= '</td>';

        // status geral
        $r .= '<td>';
        $stat = strtoupper( $sub['status'] ?? '' );
        if ( in_array( $stat, ['CANCELLED','CANCELED'], true ) ) {
            $data = $sub['cancelled_at'] ?? $sub['updated'];
            $r   .= 'Cancelado em '. date_i18n( 'd/m/Y', strtotime( $data ) );
        } else {
            $r   .= ucfirst( strtolower( $sub['status'] ?? '' ) );
        }
        $r .= '</td>';

        $r .= '</tr>';
        return $r;
    };

    // gera cada categoria
    foreach ( [
        'em_dia'     => 'Em Dia',
        'em_atraso'  => 'Em Atraso',
        'canceladas' => 'Canceladas',
    ] as $key => $titulo ) {
        $html .= "<h3>Assinaturas — {$titulo}</h3>";
        $html .= "<table class='dash-table'>
                    <tr>
                      <th>Nome</th>
                      <th>E‑mail</th>
                      <th>CPF</th>
                      <th>Pagamentos</th>
                      <th>Status</th>
                    </tr>";
        if ( empty( $grupos[ $key ] ) ) {
            $html .= "<tr><td colspan='5'><em>Nenhuma assinatura nesta categoria.</em></td></tr>";
        } else {
            foreach ( $grupos[ $key ] as $entry ) {
                $html .= $render_row( $entry['sub'], $entry['payments'] );
            }
        }
        $html .= '</table>';
    }

    return $html;
}

add_shortcode( 'dashboard_assinantes_e_pedidos', 'mostrar_dashboard_assinantes' );
