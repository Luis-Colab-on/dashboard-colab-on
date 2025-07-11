<?php
/*
Plugin Name: Meu Plugin Dashboard Assinantes
Description: Exibe todas as assinaturas Asaas e últimos pedidos WooCommerce via shortcode [dashboard_assinantes]
Version: 1.3
Author: Luis Furtado
*/

function fetch_all_asaas_subscriptions() {
    global $wpdb;
    $subs_table = $wpdb->prefix . 'processa_pagamentos_asaas_subscriptions';

    // Query que percorre toda a tabela de assinaturas
    return $wpdb->get_results(
        "SELECT * FROM {$subs_table}",
        ARRAY_A  // retorna como array associativo
    );
}

function fetch_all_wc_orders() {
    global $wpdb;
    $orders_table = $wpdb->prefix . 'wc_orders';

    // Query que percorre toda a tabela de pedidos (custom WC)
    return $wpdb->get_results(
        "SELECT * FROM {$orders_table}",
        ARRAY_A
    );
}

function mostrar_dashboard_assinantes() {
    // Busca os dados
    $assinaturas = fetch_all_asaas_subscriptions();
    $pedidos     = fetch_all_wc_orders();

    // HTML de assinaturas
    $html  = '<h3>Status de Assinaturas Asaas</h3>';
    $html .= '<p>Total de assinaturas encontradas: ' . count($assinaturas) . '</p>';
    if ( empty($assinaturas) ) {
        $html .= '<p><em>Nenhuma assinatura encontrada.</em></p>';
    } else {
        $html .= '<table border="1" cellpadding="4" cellspacing="0">';
        $headings = array_keys( $assinaturas[0] );
        $html .= '<tr>';
        foreach ( $headings as $col ) {
            $html .= '<th>' . esc_html($col) . '</th>';
        }
        $html .= '</tr>';

        // linhas
        foreach ( $assinaturas as $row ) {
            $html .= '<tr>';
            foreach ( $row as $val ) {
                $html .= '<td>' . esc_html($val) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
    }

    // HTML 
    $html .= '<h3>Últimos Pedidos WooCommerce (raw)</h3>';
    $html .= '<p>Total de pedidos encontrados: ' . count($pedidos) . '</p>';
    if ( empty($pedidos) ) {
        $html .= '<p><em>Nenhum pedido encontrado.</em></p>';
    } else {
        $html .= '<table border="1" cellpadding="4" cellspacing="0">';
        $headings = array_keys( $pedidos[0] );
        $html .= '<tr>';
        foreach ( $headings as $col ) {
            $html .= '<th>' . esc_html($col) . '</th>';
        }
        $html .= '</tr>';

        foreach ( $pedidos as $row ) {
            $html .= '<tr>';
            foreach ( $row as $val ) {
                $html .= '<td>' . esc_html($val) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
    }

    return $html;
}

add_shortcode( 'dashboard_assinantes_e_pedidos', 'mostrar_dashboard_assinantes' );
