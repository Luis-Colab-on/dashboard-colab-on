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
    $users_table = $wpdb->users; // normalmente 'wp_users'

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
 * 2) Busca as parcelas de cada assinatura
 */
function fetch_asaas_subscription_items( $subscription_id ) {
    global $wpdb;

    $items_table = $wpdb->prefix . 'processa_pagamentos_asaas_subscriptions_items';

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT *
             FROM {$items_table}
             WHERE subscription_id = %d
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
    $subs = fetch_all_asaas_subscriptions();

    // agrupa em 3 categorias
    $grupos = [
      'em_dia'     => [],
      'em_atraso'  => [],
      'canceladas' => [],
    ];

    foreach ( $subs as $sub ) {
        $items = fetch_asaas_subscription_items( $sub['id'] );

        // se a assinatura foi cancelada
        if ( in_array( strtoupper($sub['status']), ['CANCELLED','CANCELED'] ) ) {
            $grupos['canceladas'][] = compact('sub','items');
            continue;
        }

        // senão, verifica se existe parcela em atraso
        $temAtraso = false;
        foreach ( $items as $it ) {
            $st  = strtoupper( $it['status'] );
            $due = strtotime( $it['due_date'] );
            if ( $st === 'OVERDUE' || ($due < time() && $st !== 'PAID') ) {
                $temAtraso = true;
                break;
            }
        }

        if ( $temAtraso ) {
            $grupos['em_atraso'][] = compact('sub','items');
        } else {
            $grupos['em_dia'][] = compact('sub','items');
        }
    }

    // CSS inline para tabelas e caixinhas
    $html = '
    <style>
      .dash-table { width:100%; border-collapse:collapse; margin-bottom:2em; }
      .dash-table th, .dash-table td { border:1px solid #ccc; padding:6px; }
      .status-box { display:inline-block; width:12px; height:12px; margin-right:2px; vertical-align:middle; }
      .paid    { background:green; }
      .overdue { background:red; }
      .future  { background:transparent; border:1px solid #ccc; }
      .dash-section { margin-top:1.5em; }
      .dash-section h3 { margin-bottom:0.5em; }
    </style>
    ';

    // helper para renderizar cada linha
    $render_row = function( $sub, $items ) {
        $r  = '<tr>';
        // campos vindos do JOIN
        $r .= '<td>'.esc_html( $sub['customer_name']  ?? '—' ).'</td>';
        $r .= '<td>'.esc_html( $sub['customer_email'] ?? '—' ).'</td>';
        // cpf já está na tabela de assinaturas
        $r .= '<td>'.esc_html( $sub['cpf'] ).'</td>';
        // caixa de pagamentos
        $r .= '<td>';
        foreach ( $items as $it ) {
            $st  = strtoupper( $it['status'] );
            $cls = $st==='PAID'    ? 'paid'
                 : ($st==='OVERDUE' ? 'overdue' : 'future');
            $r .= "<span class='status-box {$cls}'></span>";
        }
        $r .= '</td>';
        // status geral ou data de cancelamento
        $r .= '<td>';
        if ( in_array( strtoupper($sub['status']), ['CANCELLED','CANCELED'] ) ) {
            $data = $sub['cancelled_at'] ?? $sub['updated'];
            $r .= 'Cancelado em '. date_i18n('d/m/Y', strtotime($data));
        } else {
            $r .= ucfirst( strtolower($sub['status']) );
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
        $html .= '<tr><th>Nome</th><th>E-mail</th><th>CPF</th><th>Pagamentos</th><th>Status</th></tr>';

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
