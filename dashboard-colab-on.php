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
 *    - Ordena por dueDate/created
 *    - Remove linhas com status "refunded"
 *    - Deduplica por installmentNumber (mantém a melhor linha: paga > atrasada > futura; em empate, a mais recente)
 */
function fetch_asaas_subscription_payments( $subscriptionID ) {
    global $wpdb;
    $payments_table = $wpdb->prefix . 'processa_pagamentos_asaas';

    $sql = $wpdb->prepare(
        "
        SELECT *
        FROM {$payments_table}
        WHERE type = %s
          AND orderID = %s
        ORDER BY dueDate ASC, created ASC
        ",
        'assinatura',
        $subscriptionID
    );

    $rows  = $wpdb->get_results( $sql, ARRAY_A );
    $now   = time();

    // 1) filtra REFUNDED
    $rows = array_values(array_filter($rows, function($r){
        return !_asaas_is_refunded_payment($r);
    }));

    // 2) dedup por installmentNumber (mantém a "melhor" linha)
    $byInstallment = [];
    $noIndex       = []; // quando não houver installmentNumber

    foreach ($rows as $r) {
        $idx = isset($r['installmentNumber']) ? (int)$r['installmentNumber'] - 1 : null;

        // score: paid > overdue > future
        $p_stat = strtoupper($r['paymentStatus'] ?? '');
        $due_ts = !empty($r['dueDate']) ? strtotime($r['dueDate']) : 0;

        $is_paid    = ($p_stat === 'PAYED' || $p_stat === 'PAID' || $p_stat === 'RECEIVED' || $p_stat === 'CONFIRMED' || $p_stat === 'RECEIVED_IN_CASH');
        $is_overdue = (!$is_paid) && ( ($due_ts && $due_ts < $now) || ($p_stat === 'OVERDUE' || $p_stat === 'INACTIVE') );

        $score = $is_paid ? 3 : ($is_overdue ? 2 : 1);
        $ts    = 0;
        if (!empty($r['updated']))   $ts = max($ts, strtotime($r['updated']));
        if (!empty($r['createdAt'])) $ts = max($ts, strtotime($r['createdAt']));
        if (!empty($r['created']))   $ts = max($ts, strtotime($r['created']));

        $r['_score'] = $score;
        $r['_ts']    = $ts;

        if ($idx === null || $idx < 0) {
            $noIndex[] = $r;
        } else {
            if (!isset($byInstallment[$idx])) {
                $byInstallment[$idx] = $r;
            } else {
                $best = $byInstallment[$idx];
                if ($r['_score'] > $best['_score'] || ($r['_score'] === $best['_score'] && $r['_ts'] > $best['_ts'])) {
                    $byInstallment[$idx] = $r;
                }
            }
        }
    }

    ksort($byInstallment, SORT_NUMERIC);

    // retorna deduplicados; os sem índice vão por último (serão alocados nos "slots" livres pelo render)
    return array_values(array_merge($byInstallment, $noIndex));
}


/** ===================== Helpers ===================== */

/** Status pagos/atrasados (cobre variações comuns do Asaas) */
function _asaas_is_paid_status( $status ) {
    $st = strtoupper( trim( (string) $status ) );
    $paid_statuses = [ 'PAYED', 'PAID', 'RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH' ];
    return in_array( $st, $paid_statuses, true );
}
function _asaas_is_overdue_status( $status ) {
    $st = strtoupper( trim( (string) $status ) );
    return in_array( $st, [ 'OVERDUE', 'INACTIVE' ], true );
}

/** Extrai o título real do produto após "Assinatura #1234 - " */
function mpda_extract_title_from_description( $desc ) {
    if ( ! $desc ) return '';
    $raw = trim( wp_strip_all_tags( (string) $desc ) );
    if ( preg_match( '/^\s*assinatura\s*#?\s*\d+\s*-\s*(.+)$/i', $raw, $m ) ) {
        return trim( $m[1] ); // tudo após o hífen
    }
    return $raw;
}

/**
 * Acha product_id a partir do description (usando só o trecho depois do prefixo).
 * Tenta: título exato -> LIKE -> busca do Woo.
 * Retorna: ['product_id'=>int,'src'=>'description_title_exact|description_title_like|description_wc_search']
 */
function mpda_find_product_id_from_description( $desc ) {
    static $cache = [];
    $title = mpda_extract_title_from_description( $desc );
    $key   = md5( strtolower( $title ) );
    if ( isset( $cache[ $key ] ) ) return $cache[ $key ];

    $ret = [ 'product_id' => 0, 'src' => null ];
    if ( $title === '' ) return $cache[ $key ] = $ret;

    // 1) título exato
    $post = get_page_by_title( $title, OBJECT, 'product' );
    if ( $post ) {
        return $cache[ $key ] = [ 'product_id' => (int) $post->ID, 'src' => 'description_title_exact' ];
    }

    // 2) LIKE
    global $wpdb;
    $pid = $wpdb->get_var( $wpdb->prepare(
        "SELECT ID
         FROM {$wpdb->posts}
         WHERE post_type='product'
           AND post_status IN('publish','private')
           AND post_title LIKE %s
         ORDER BY (post_title=%s) DESC, CHAR_LENGTH(post_title) ASC
         LIMIT 1",
        '%' . $wpdb->esc_like( $title ) . '%', $title
    ) );
    if ( $pid ) {
        return $cache[ $key ] = [ 'product_id' => (int) $pid, 'src' => 'description_title_like' ];
    }

    // 3) Fallback: busca por s=
    if ( function_exists( 'wc_get_products' ) ) {
        $ids = wc_get_products( [ 'limit' => 1, 'status' => [ 'publish','private' ], 's' => $title, 'return' => 'ids' ] );
        if ( ! empty( $ids ) ) {
            return $cache[ $key ] = [ 'product_id' => (int) $ids[0], 'src' => 'description_wc_search' ];
        }
    }

    return $cache[ $key ] = $ret;
}

/**
 * Descobre dinamicamente o total de parcelas/meses e a fonte.
 * Ordem:
 * 1) Woo Subscriptions (length do produto, se houver order_id ligado)
 * 2) Meta do produto (_subscription_length, etc.)
 * 3) Produto via description -> length/meta
 * 4) Campos da própria assinatura (total_installments/installments/parcelas/months/duration)
 * 5) Pelos pagamentos (max installmentNumber, senão count)
 * Retorna: ['total'=>int,'product_id'=>int,'source'=>string,'source_meta'=>string|null]
 */
function mpda_guess_total_installments( array $sub, array $payments ) {
    $result = [
        'total'      => null,
        'product_id' => 0,
        'source'     => 'fallback',
        'source_meta'=> null,
    ];
    $meta_keys = [ '_subscription_length', '_billing_length', 'assinatura_meses', 'numero_de_meses', 'duracao_meses' ];

    // 1) Woo Subscriptions (se você tiver esse vínculo por order_id)
    if ( function_exists( 'wcs_get_subscriptions_for_order' ) && ! empty( $sub['order_id'] ) ) {
        $subs_wc = wcs_get_subscriptions_for_order( $sub['order_id'], [ 'order_type' => 'parent' ] );
        if ( ! empty( $subs_wc ) ) {
            $woo_subscription = reset( $subs_wc );
            if ( $woo_subscription && is_a( $woo_subscription, 'WC_Subscription' ) ) {
                $items = $woo_subscription->get_items();
                if ( ! empty( $items ) ) {
                    $item                 = reset( $items );
                    $result['product_id'] = (int) $item->get_product_id();
                    if ( class_exists( 'WC_Subscriptions_Product' ) && method_exists( 'WC_Subscriptions_Product', 'get_length' ) ) {
                        $length = \WC_Subscriptions_Product::get_length( $result['product_id'] );
                        if ( is_numeric( $length ) && (int) $length > 0 ) {
                            $result['total']  = (int) $length;
                            $result['source'] = 'wc_subscriptions_length';
                        }
                    }
                }
            }
        }
    }

    // 2) Meta do produto — se já tiver product_id
    if ( ! $result['total'] && $result['product_id'] ) {
        foreach ( $meta_keys as $mk ) {
            $v = get_post_meta( $result['product_id'], $mk, true );
            if ( $v !== '' && is_numeric( $v ) && (int) $v > 0 ) {
                $result['total']      = (int) $v;
                $result['source']     = 'product_meta:' . $mk;
                $result['source_meta']= $mk;
                break;
            }
        }
    }

    // 3) Produto via description -> tenta length/meta
    if ( ! $result['total'] && ! $result['product_id'] ) {
        $found = mpda_find_product_id_from_description( $sub['description'] ?? '' );
        if ( $found['product_id'] ) {
            $result['product_id'] = (int) $found['product_id'];
            $result['source']     = 'description_map:' . $found['src'];

            if ( class_exists( 'WC_Subscriptions_Product' ) && method_exists( 'WC_Subscriptions_Product', 'get_length' ) ) {
                $length = \WC_Subscriptions_Product::get_length( $result['product_id'] );
                if ( is_numeric( $length ) && (int) $length > 0 ) {
                    $result['total'] = (int) $length; // mantém fonte description_map:*
                }
            }
            if ( ! $result['total'] ) {
                foreach ( $meta_keys as $mk ) {
                    $v = get_post_meta( $result['product_id'], $mk, true );
                    if ( $v !== '' && is_numeric( $v ) && (int) $v > 0 ) {
                        $result['total']      = (int) $v;
                        $result['source']    .= ' + product_meta:' . $mk;
                        $result['source_meta']= $mk;
                        break;
                    }
                }
            }
        }
    }

    // 4) Campos próprios da assinatura (se existirem)
    if ( ! $result['total'] ) {
        foreach ( [ 'total_installments', 'installments', 'parcelas', 'months', 'duration' ] as $pf ) {
            if ( isset( $sub[ $pf ] ) && is_numeric( $sub[ $pf ] ) && (int) $sub[ $pf ] > 0 ) {
                $result['total']  = (int) $sub[ $pf ];
                $result['source'] = 'subscription_field:' . $pf;
                break;
            }
        }
    }

    // 5) Deriva pelos pagamentos
    if ( ! $result['total'] ) {
        $max_install = 0;
        foreach ( $payments as $p ) {
            if ( isset( $p['installmentNumber'] ) && is_numeric( $p['installmentNumber'] ) ) {
                $max_install = max( $max_install, (int) $p['installmentNumber'] );
            }
        }
        if ( $max_install > 0 ) {
            $result['total']  = $max_install;
            $result['source'] = 'payments:max_installmentNumber';
        } else {
            $result['total']  = max( 1, (int) count( $payments ) );
            $result['source'] = 'payments:count';
        }
    }

    // Clamp + filtro
    $result['total'] = max( 1, min( (int) $result['total'], 60 ) );
    $result['total'] = (int) apply_filters( 'mpda_total_installments', $result['total'], $sub, $payments, $result['product_id'] );

    return $result;
}

/** Ignora pagamentos reembolsados (status/refund) */
function _asaas_is_refunded_payment( array $row ) : bool {
    $a = strtoupper( trim( (string)($row['status'] ?? '') ) );
    $b = strtoupper( trim( (string)($row['paymentStatus'] ?? '') ) );

    // considera qualquer ocorrência de "REFUND" (ex.: REFUNDED) em status ou paymentStatus
    if (strpos($a, 'REFUND') !== false || strpos($b, 'REFUND') !== false) {
        return true;
    }
    // (opcional) trate chargeback como reembolso também
    // if (strpos($a, 'CHARGEBACK') !== false || strpos($b, 'CHARGEBACK') !== false) return true;

    return false;
}


/** ===================== UI ===================== */

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
                $due_ts = ! empty( $p['dueDate'] ) ? strtotime( $p['dueDate'] ) : false;
                $is_paid    = _asaas_is_paid_status( $p_stat );
                $is_overdue = ( ! $is_paid ) && ( ( $due_ts && $due_ts < $agora ) || _asaas_is_overdue_status( $p_stat ) );
                if ( $is_overdue ) { $hasOverdue = true; break; }
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
  #search-button {background-color: #005b96;color: white;border: none;cursor: pointer;font-size: 16px;}
  #search-button:hover {background-color: #003f7c;}
  .debug-info strong {
    font-size: 1em;
    color: #333;
    margin-top: 8px;
}


</style>';

        // Campo de busca
    $html .= '<div style="width:100%;">
    <input
        type="text"
        id="search-assinantes"
        placeholder="Buscar por nome, e-mail ou CPF…"
        style="margin-bottom:12px;padding:6px;width:100%;max-width:400px;border-radius: 5px;border: 1px solid;"
    >
    <button id="search-button" style="padding:6px 12px; margin-top: 10px; border-radius: 5px;">Buscar</button>
</div>
';

    // Script de filtragem
    $html .= "
    <script>
    document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('search-assinantes');
    const button = document.getElementById('search-button'); // Novo botão de busca

    // Função para normalizar a string removendo acentos e caracteres especiais
    function normalizeString(str) {
        return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); // Remove acentos e converte para minúsculas
    }

    // Função para remover pontos e traços de CPF
    function normalizeCPF(cpf) {
        return cpf.replace(/[^\d]/g, ''); // Remove qualquer caractere que não seja número
    }

    button.addEventListener('click', function() {
        const filter = normalizeString(input.value.trim());

        document.querySelectorAll('.dash-table').forEach(table => {
            const rows = table.tBodies[0]?.rows || table.querySelectorAll('tr');
            Array.from(rows).forEach(row => {
                const cols = row.cells;
                if (!cols || cols.length < 3) return;
                
                const nome = normalizeString(cols[0].textContent.toLowerCase());
                const email = normalizeString(cols[1].textContent.toLowerCase());
                const cpf = normalizeCPF(cols[2].textContent.toLowerCase());

                // Mostra a linha se a busca corresponder a nome, email ou CPF
                row.style.display = (nome.includes(filter) || email.includes(filter) || cpf.includes(filter)) ? '' : 'none';
            });
        });
    });
});

    </script>
    ";

    // Função de renderização de linhas com mapeamento de parcelas (dinâmico)
$render_row = function( $sub, $payments ) use ( $agora, $expiredStatuses ) {

    // Dinâmico: total de parcelas + product_id + fonte
    $guess             = mpda_guess_total_installments( $sub, $payments );
    $totalInstallments = $guess['total'];
    $product_id        = $guess['product_id'];
    $source            = $guess['source'];

    // Extrair o nome do curso da descrição
    $course_name = mpda_extract_title_from_description($sub['description']);

    // Cria slots e preenche por installmentNumber (ou primeira posição livre)
    $installments = array_fill( 0, $totalInstallments, null );
    foreach ( $payments as $p ) {
        $idx = isset( $p['installmentNumber'] ) ? ( (int) $p['installmentNumber'] - 1 ) : null;
        if ( $idx === null || $idx < 0 || $idx >= $totalInstallments ) {
            $idx = array_search( null, $installments, true );
        }
        if ( $idx !== false ) {
            $installments[ $idx ] = $p;
        }
    }

    // Contadores
    $countPaid = $countOverdue = $countFuture = 0;

    $r  = '<tr>';
    $r .= '<td>'. esc_html( $sub['customer_name'] ?? '—' ) .'</td>';
    $r .= '<td>'. esc_html( $sub['customer_email'] ?? '—' ) .'</td>';
    $r .= '<td>'. esc_html( $sub['cpf'] ?? '—' ) .'</td>';
    $r .= '<td>';

    // Debugs
    $r .= '<div class="debug-info">ID da Assinatura: '. esc_html( $sub['subscriptionID'] ?? '—' ) .'</div>';
    $r .= '<div class="debug-info">ID do Produto (WooCommerce): '. esc_html( $product_id ?: '—' ) .'</div>';
    $r .= '<div class="debug-info">Parcelas previstas: '. esc_html( $totalInstallments ) .' <small>(fonte: '. esc_html( $source ) .')</small></div>';

    // Adicionar o nome do curso logo abaixo do debug
    if (!empty($course_name)) {
        $r .= '<div class="debug-info"><strong>Curso: </strong>' . esc_html($course_name) . '</div>';
    }

    // Renderiza as parcelas
    foreach ( $installments as $p ) {
        if ( $p ) {
            $val = isset( $p['value'] ) ? number_format( (float) $p['value'], 2, ',', '.' ) : '0,00';

            $created_raw = $p['created'] ?? $p['createdAt'] ?? '';
            $created_ts  = $created_raw ? strtotime( $created_raw ) : false;
            $created     = $created_ts ? date_i18n( 'd/m/Y - H:i', $created_ts ) : '—';

            $p_stat = strtoupper( $p['paymentStatus'] ?? '' );
            $due_ts = ! empty( $p['dueDate'] ) ? strtotime( $p['dueDate'] ) : false;

            $is_paid    = _asaas_is_paid_status( $p_stat );
            $is_overdue = ( ! $is_paid ) && ( ( $due_ts && $due_ts < $agora ) || _asaas_is_overdue_status( $p_stat ) );

            if ( $is_paid ) {
                $pay_raw = $p['paymentDate'] ?? $p['confirmedDate'] ?? '';
                $pay_ts  = $pay_raw ? strtotime( $pay_raw ) : false;
                $pag     = $pay_ts ? date_i18n( 'd/m/Y', $pay_ts ) : '—';
                $cls     = 'paid';
                $countPaid++;
            } elseif ( $is_overdue ) {
                $pag     = 'ATRASADA';
                $cls     = 'overdue';
                $countOverdue++;
            } else {
                $pag     = '—';
                $cls     = 'future';
                $countFuture++;
            }

            $tooltip = "Valor: R$ {$val}\nCriação: {$created}\nPagamento: {$pag}";
        } else {
            $tooltip = 'Parcela não criada';
            $cls     = 'future';
            $countFuture++;
        }

        $r .= '<span class="status-box '. esc_attr( $cls ) .'" data-tooltip="'. esc_attr( $tooltip ) .'"></span>';
    }

    $r .= "<div class='debug-info'>{$countPaid} pagas<br>{$countOverdue} atrasadas<br>{$countFuture} futuras</div>";
    $r .= '</td>';

    // Coluna Status assinatura
    $stat = strtoupper( trim( $sub['status'] ?? '' ) );
    $r .= '<td>';
    if ( in_array( $stat, $expiredStatuses, true ) ) {
        $date_raw = $sub['cancelled_at'] ?? $sub['cancelledAt'] ?? $sub['canceledAt'] ?? $sub['expiredAt'] ?? $sub['updated'] ?? '';
        $date_ts  = $date_raw ? strtotime( $date_raw ) : false;
        $r .= $date_ts ? 'Cancelado em ' . esc_html( date_i18n( 'd/m/Y', $date_ts ) ) : 'Cancelado';
    } else {
        switch ( $stat ) {
            case 'ACTIVE':   $label = 'Ativo';   break;
            case 'INACTIVE': $label = 'Inativo'; break;
            default:         $label = ucfirst( strtolower( $sub['status'] ?? '—' ) );
        }
        $r .= esc_html( $label );
    }
    $r .= '</td></tr>';

    return $r;
};


    // Monta tabelas por categoria
    foreach ( [ 'em_atraso' => 'Em Atraso', 'em_dia' => 'Em Dia', 'canceladas' => 'Canceladas' ] as $key => $titulo ) {
        $html .= "<h3>Assinaturas — {$titulo}</h3>";
        $html .= "<table class='dash-table'><thead><tr><th>Nome</th><th>E-mail</th><th>CPF</th><th>Pagamentos</th><th>Status</th></tr></thead><tbody>";
        if ( empty( $grupos[ $key ] ) ) {
            $html .= "<tr><td colspan='5'><em>Nenhuma assinatura nesta categoria.</em></td></tr>";
        } else {
            foreach ( $grupos[ $key ] as $entry ) {
                $html .= $render_row( $entry['sub'], $entry['payments'] );
            }
        }
        $html .= '</tbody></table>';
    }

    return $html;
}

add_shortcode( 'dashboard_assinantes_e_pedidos', 'mostrar_dashboard_assinantes' );