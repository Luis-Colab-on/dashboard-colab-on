<?php
/*
Plugin Name: Meu Plugin Dashboard Assinantes
Description: Exibe assinaturas Asaas agrupadas por status (Em Dia, Em Atraso e Canceladas) via shortcode [dashboard_assinantes_e_pedidos]
Version: 1.14
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

    $payments = $wpdb->get_results( $sql, ARRAY_A );
    usort($payments, function($a, $b){
        return strtotime($a['dueDate']) <=> strtotime($b['dueDate']);
    });
    return $payments;
}


/**
 * 3) Monta o HTML do dashboard, com tooltips nas parcelas
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

    // Classifica em grupos
    foreach ( $subs as $sub ) {
        $stat_raw = strtoupper( trim( $sub['status'] ?? '' ) );
            $payments = fetch_asaas_subscription_payments( $sub['subscriptionID'] );

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

    // Campo de busca
    $html .= '<div style="width:100%;">
        <input
        type="text"
        id="search-assinantes"
        placeholder="Buscar por nome, e-mail ou CPF…"
        style="margin-bottom:12px;padding:6px;width:100%;max-width:400px;border-radius: 5px;border: 1px solid;"
        >
    </div>';

    // Script de filtragem
    $html .= "
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      const input = document.getElementById('search-assinantes');
      input.addEventListener('input', function() {
        const filter = input.value.trim().toLowerCase();
        document.querySelectorAll('.dash-table').forEach(table => {
          const rows = table.tBodies[0]?.rows || table.querySelectorAll('tr');
          Array.from(rows).forEach(row => {
            const cols = row.cells;
            if (!cols || cols.length < 3) return;
            const nome = cols[0].textContent.toLowerCase();
            const email = cols[1].textContent.toLowerCase();
            const cpf = cols[2].textContent.toLowerCase();
            row.style.display = (nome.includes(filter) || email.includes(filter) || cpf.includes(filter)) ? '' : 'none';
          });
        });
      });
    });
    </script>
    ";

// Função de renderização de linhas com mapeamento de parcelas
$render_row = function( $sub, $payments ) use ( $agora, $expiredStatuses ) {
    $totalInstallments = 6; // fallback padrão

    // === NOVA LÓGICA: buscar o número de parcelas do produto no WooCommerce ===
    if ( function_exists( 'wcs_get_subscriptions_for_order' ) && ! empty( $sub['order_id'] ) ) {
        // Busca as assinaturas do WooCommerce vinculadas ao pedido original
        $subs_wc = wcs_get_subscriptions_for_order( $sub['order_id'], [ 'order_type' => 'parent' ] );
        if ( ! empty( $subs_wc ) ) {
            $woo_subscription = reset( $subs_wc );
            if ( $woo_subscription && is_a( $woo_subscription, 'WC_Subscription' ) ) {
                $items = $woo_subscription->get_items();
                if ( ! empty( $items ) ) {
                    $item       = reset( $items );
                    $product_id = $item->get_product_id();

                    if ( function_exists( 'WC_Subscriptions_Product::get_length' ) || method_exists( 'WC_Subscriptions_Product', 'get_length' ) ) {
                        $length = \WC_Subscriptions_Product::get_length( $product_id );
                        if ( $length > 0 ) {
                            $totalInstallments = (int) $length;
                        }
                    }
                }
            }
        }
    }
    // ==========================================================================

    // Cria slots vazios e preenche
    $installments = array_fill(0, $totalInstallments, null);
    foreach ( $payments as $p ) {
        $idx = isset( $p['installmentNumber'] ) ? (int)$p['installmentNumber'] - 1 : null;
        if ( $idx === null || $idx < 0 || $idx >= $totalInstallments ) {
            $idx = array_search( null, $installments, true );
        }
        if ( $idx !== false ) {
            $installments[ $idx ] = $p;
        }
    }

    // Contagem de pagas e atrasadas
    $countPaid = $countOverdue = 0;
    foreach ( $installments as $p ) {
        if ( $p ) {
            $p_stat = strtoupper( $p['paymentStatus'] ?? '' );
            $due    = strtotime( $p['dueDate'] ?? '' );
            if ( $p_stat === 'PAYED' ) {
                $countPaid++;
            } elseif ( in_array( $p_stat, ['OVERDUE','INACTIVE'], true ) || ( $due && $due < $agora && $p_stat !== 'PAYED' ) ) {
                $countOverdue++;
            }
        }
    }
    $countFuture = max( 0, $totalInstallments - $countPaid - $countOverdue );

    $r  = '<tr>';
    $r .= '<td>'. esc_html( $sub['customer_name'] ?? '—' ) .'</td>';
    $r .= '<td>'. esc_html( $sub['customer_email'] ?? '—' ) .'</td>';
    $r .= '<td>'. esc_html( $sub['cpf'] ?? '—' ) .'</td>';
    $r .= '<td>';
    $r .= '<div class="debug-info">ID do Produto: '. esc_html( $sub['subscriptionID'] ?? '—' ). '</div>';
    $r .= "<div class='debug-info'>{$countPaid} pagas<br>{$countOverdue} atrasadas<br>{$countFuture} futuras</div>";

    // Renderiza cada parcelinha
    foreach ( $installments as $i => $p ) {
        if ( $p ) {
            $val     = number_format( $p['value'], 2, ',', '.' );
            $created_raw = $p['created'] ?? $p['createdAt'] ?? '';
            $created = date_i18n('d/m/Y - H:i', strtotime( $created_raw ));
            $p_stat  = strtoupper( $p['paymentStatus'] ?? '' );
            $due_ts  = strtotime( $p['dueDate'] ?? '' );
            if ( $p_stat === 'PAYED' ) {
                $pag = date_i18n('d/m/Y', strtotime( $p['paymentDate'] ?? '' ));
            } elseif ( in_array( $p_stat, ['OVERDUE','INACTIVE'], true ) || ( $due_ts && $due_ts < $agora && $p_stat !== 'PAYED' ) ) {
                $pag = 'ATRASADA';
            } else {
                $pag = '—';
            }
            $tooltip = "Valor: R$ {$val}\nCriação: {$created}\nPagamento: {$pag}";
            $cls = $i < $countPaid ? 'paid' : ( $i < $countPaid + $countOverdue ? 'overdue' : 'future' );
        } else {
            $tooltip = 'Parcela não criada';
            $cls     = 'future';
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
        switch ( $stat ) {
            case 'ACTIVE':
                $label = 'Ativo';
                break;
            case 'INACTIVE':
                $label = 'Inativo';
                break;
            default:
                $label = ucfirst( strtolower( $sub['status'] ?? '—' ) );
        }
        $r .= $label;
    }

    $r .= '</td></tr>';
    return $r;
};

    // Monta tabelas por categoria
    foreach ([ 'em_atraso'=>'Em Atraso', 'em_dia'=>'Em Dia', 'canceladas'=>'Canceladas'] as $key=>$titulo) {
        $html .= "<h3>Assinaturas — {$titulo}</h3>";
        $html .= "<table class='dash-table'><thead><tr><th>Nome</th><th>E‑mail</th><th>CPF</th><th>Pagamentos</th><th>Status</th></thead><tbody>";
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
