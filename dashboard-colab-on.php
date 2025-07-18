<?php
/*
Plugin Name: Meu Plugin Dashboard Assinantes
Description: Exibe assinaturas Asaas agrupadas por status (Em Dia, Em Atraso e Canceladas) via shortcode [dashboard_assinantes_e_pedidos]
Version: 1.7
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

    $subs = $wpdb->get_results( $sql, ARRAY_A );

    foreach ( $subs as &$sub ) {
        if ( empty( $sub['customer_name'] ) && ! empty( $sub['nome_cliente'] ) ) {
            $sub['customer_name'] = $sub['nome_cliente'];
        }
        if ( empty( $sub['customer_email'] ) && ! empty( $sub['email_cliente'] ) ) {
            $sub['customer_email'] = $sub['email_cliente'];
        }
    }
    unset( $sub );

    return $subs;
}




/**
 * 2) Busca as parcelas de cada assinatura
 */
function fetch_asaas_subscription_items( $subscription_id ) {
    global $wpdb;
    $items_table = $wpdb->prefix . 'processa_pagamentos_asaas_subscriptions_items';

    // Se a coluna na sua tabela for "subscriptionId" em vez de "subscription_id",
    // ajuste abaixo para '%s' e substitua pelo nome correto.
    $col = 'subscription_id'; 

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT *
             FROM {$items_table}
             WHERE {$col} = %s
             ORDER BY due_date ASC",
            $subscription_id
        ),
        ARRAY_A
    );
}




/**
 * 3) Monta o HTML do dashboard
 */
function mostrar_dashboard_assinantes() {
    $subs   = fetch_all_asaas_subscriptions();
    $agora  = time();
    $grupos = [
      'em_dia'     => [],
      'em_atraso'  => [],
      'canceladas' => [],
    ];

    // definir agrupamentos de status
    $expiredStatuses  = ['CANCELLED','CANCELED','EXPIRED'];
    $overdueStatuses  = ['OVERDUE','INACTIVE']; 

    

    foreach ( $subs as $sub ) {
        $stat  = strtoupper( trim( $sub['status'] ?? '' ) );
        $items = fetch_asaas_subscription_items( $sub['id'] );

        // 1) Expired/Canceled ➞ Canceladas
        if ( in_array( $stat, $expiredStatuses, true ) ) {
            $grupos['canceladas'][] = compact('sub','items');
            continue;
        }

        // 2) Inactive ou OVERDUE ➞ Em Atraso
        $hasOverdue = in_array( $stat, $overdueStatuses, true );

        //    ou qualquer parcela vencida (por data/status)
        if ( ! $hasOverdue ) {
            foreach ( $items as $it ) {
                $it_stat = strtoupper( $it['status'] ?? '' );
                $due     = strtotime( $it['due_date'] );
                if ( in_array( $it_stat, ['OVERDUE'], true )
                  || ( $due && $due < $agora && $it_stat !== 'PAID' ) ) {
                    $hasOverdue = true;
                    break;
                }
            }
        }

        if ( $hasOverdue ) {
            $grupos['em_atraso'][] = compact('sub','items');
        } else {
            // 3) resto ➞ Em Dia
            $grupos['em_dia'][]    = compact('sub','items');
        }
    }

    // CSS inline para tabelas e caixinhas
    $html = '
    <style>
        .dash-table { width:100%; border-collapse:collapse; margin-bottom:2em; }
        .dash-table th, .dash-table td { border:2px solid #000; padding:5px; }
        .nome, .email, .cpf, .status { width:15%; }
        .dash-section { max-width:100%; margin-bottom:1.5em; }
        .dash-section h3 { margin-bottom:0.5em; }

        .status-box {
            display: inline-block;
            width: 10%;
            height: 50px;
            margin-right: 4px;
            vertical-align: middle;
            border: 2px solid transparent;
        }
        .status-box.paid   { background: green; }
        .status-box.overdue{ background: red; }
        .status-box.future { background: transparent; border-color: #000; }
    </style>

    ';

    // renderizar cada linha
    $render_row = function( $sub, $items ) {
    // 1) descobre quantas parcelas tem essa assinatura
        $defaultInstallments = 6;  // troque para o valor que faz sentido no seu caso
        $totalInstallments   = isset($sub['installments'])
                            ? (int) $sub['installments']
                            : $defaultInstallments;



    // opcional: indexa $items pelo número da parcela, se você tiver esse campo
    // exemplo: $map[$it['installment_number']] = $it;
    // para este exemplo assumimos que $items já vem ordenado por due_date

    $r  = '<tr>';
    $r .= '<td>'. esc_html( $sub['customer_name']  ?? '—' ) .'</td>';
    $r .= '<td>'. esc_html( $sub['customer_email'] ?? '—' ) .'</td>';
    $r .= '<td>'. esc_html( $sub['cpf'] ) .'</td>';

    // 2) gera as caixinhas
    $r .= '<td>';
    for ( $i = 0; $i < $totalInstallments; $i++ ) {
        if ( isset( $items[ $i ] ) ) {
            $it   = $items[ $i ];
            $st   = strtoupper( $it['status'] );
            // trata PAID, OVERDUE e INACTIVE como “atraso”
            if ( $st === 'PAID' ) {
                $cls = 'paid';
            } elseif ( in_array( $st, ['OVERDUE','INACTIVE'], true ) ) {
                $cls = 'overdue';
            } else {
                $cls = 'future';
            }
        } else {
            // parcela ainda não foi gerada
            $cls = 'future';
        }
        $r .= "<span class='status-box {$cls}'></span>";
    }
    $r .= '</td>';

    // 3) status geral / data de cancelamento
    $r .= '<td>';
    $stat = strtoupper( $sub['status'] );
    if ( in_array( $stat, ['CANCELLED','CANCELED'], true ) ) {
        $data = $sub['cancelled_at'] ?? $sub['updated'];
        $r   .= 'Cancelado em '. date_i18n('d/m/Y', strtotime($data));
    } else {
        $r   .= ucfirst( strtolower($sub['status']) );
    }
    $r .= '</td>';

    $r .= '</tr>';
    return $r;
};

    // monta cada seção
    foreach ([
        'em_dia'     => 'Em Dia',
        'em_atraso'  => 'Em Atraso',
        'canceladas' => 'Canceladas',
    ] as $key => $titulo ) {
        $html .= "<div class='dash-section'>";
        $html .= "<h3>Assinaturas — {$titulo}</h3>";
        $html .= "<table class='dash-table'>";
        $html .= "<tr><th class='nome'>Nome</th><th class='email'>E-mail</th><th class='cpf'>CPF</th><th class='recorrencia'>Pagamentos</th><th class='status'>Status</th></tr>";

        if ( empty( $grupos[$key] ) ) {
            $html .= "<tr><td colspan='5'><em>Nenhuma assinatura nesta categoria.</em></td></tr>";
        } else {
            foreach ( $grupos[$key] as $entry ) {
                $html .= $render_row( $entry['sub'], $entry['items'] );
            }
        }

        $html .= '</table></div>';
    }

    return $html;
}

add_shortcode( 'dashboard_assinantes_e_pedidos', 'mostrar_dashboard_assinantes' );
