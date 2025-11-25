<?php
/*
Plugin Name: Meu Plugin Dashboard Assinantes
Description: Exibe assinaturas Asaas agrupadas por status (Em Dia, Em Atraso e Canceladas) via shortcode [dashboard_assinantes_e_pedidos]
Version: 1.20
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
 * Gate: apenas admins podem ver o dashboard.
 */
function dash_get_scope_id($filter_id, $filter_base_id = 0) {
    $fid = (int)$filter_id;
    $fbase = (int)$filter_base_id;
    return $fid ?: $fbase;
}

/**
 * Gate: apenas admins podem ver o dashboard.
 * Quando há ID no shortcode, valida se o usuário tem permissão para aquele ID específico.
 */
function dash_user_can_view_dashboard($filter_id = 0, $filter_base_id = 0) {
    if (!is_user_logged_in()) return false;
    if (current_user_can('manage_options')) return true;
    $scope = dash_get_scope_id($filter_id, $filter_base_id);
    $alt_scope = ($filter_base_id && $filter_base_id !== $scope) ? (int)$filter_base_id : 0;
    return dash_is_user_whitelisted(get_current_user_id(), $scope, $alt_scope);
}

/**
 * URL atual (para redirect do login).
 */
function dash_get_current_url() {
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
    $uri  = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
    $uri  = is_string($uri) ? preg_replace('#\s+#', '', $uri) : '';
    if (empty($host)) {
        return home_url();
    }
    $scheme = is_ssl() ? 'https://' : 'http://';
    return esc_url_raw($scheme . $host . $uri);
}

/**
 * Detecta se a página tem o shortcode (forma principal ou alias).
 */
function dash_page_has_dashboard_shortcode() {
    if (is_admin()) return false;
    global $post;
    if ($post instanceof WP_Post) {
        $content = $post->post_content ?? '';
        if (has_shortcode($content, 'dashboard_assinantes_e_pedidos')) {
            return true;
        }
        if (preg_match('/\[dashboard_assinantes_e_pedidos_\d+/i', $content)) {
            return true;
        }
    }
    return false;
}

/**
 * Detecta o ID do shortcode na página (se houver).
 */
function dash_detect_shortcode_scope_id() {
    if (is_admin()) return 0;
    global $post;
    if (!$post instanceof WP_Post) return 0;
    $content = $post->post_content ?? '';
    if (!is_string($content) || $content === '') return 0;

    if (preg_match('/dashboard_assinantes_e_pedidos_(\d+)/i', $content, $m)) {
        return (int)$m[1];
    }
    if (preg_match('/\[dashboard_assinantes_e_pedidos[^\]]*(id|corseid)\s*=\s*["\']?\s*(\d+)/i', $content, $m)) {
        return (int)$m[2];
    }
    return 0;
}

/**
 * Renderiza o bloco de login quando o usuário não pode ver o dashboard.
 * @param int $filter_id      ID do shortcode (se houver).
 * @param int $filter_base_id ID base do produto (quando houver relação pai/filho).
 */
function dash_render_login_prompt($filter_id = 0, $filter_base_id = 0) {
    $redirect = dash_get_current_url();
    $is_logged = is_user_logged_in();
    $scope_id = dash_get_scope_id($filter_id, $filter_base_id);
    $uid = $is_logged ? get_current_user_id() : 0;
    $status_meta = $is_logged ? dash_get_request_status_meta($uid) : ['status'=>'','updated'=>0];
    $status = $status_meta['status'] ?? '';
    $scope_allowed = $is_logged ? dash_is_user_whitelisted($uid, $scope_id, (int)$filter_base_id) : false;
    // Evita loop de reload quando o status geral é "approved" mas o scope atual não está liberado
    if (!$scope_allowed && $status === 'approved') {
        $status = '';
    }
    $has_pending_scope = $is_logged ? dash_user_has_pending_for_scope($uid, $scope_id) : false;
    if (!$scope_allowed && !$has_pending_scope && $status === 'pending') {
        $status = '';
    }
    // Status vazio quando não há pendência para este scope
    if (!$scope_allowed && !$has_pending_scope && $scope_id > 0) {
        $status = '';
    }
    $message  = $is_logged
        ? 'Sua conta ainda não tem permissão para ver o dashboard. Solicite aprovação para continuar.'
        : 'Informe seu usuário ou e-mail e senha para acessar o dashboard.';

    $login_form = (!$is_logged && function_exists('wp_login_form'))
        ? wp_login_form([
            'echo'           => false,
            'redirect'       => $redirect,
            'remember'       => true,
            'label_username' => 'Usuário ou e-mail',
            'label_password' => 'Senha',
            'label_log_in'   => 'Acessar',
            'label_remember' => 'Lembrar de mim',
        ])
        : '';

    $logout_link = '';
    if ($is_logged) {
        $logout_link = '<p class="dash-login-logout"><a href="'.esc_url(wp_logout_url($redirect)).'">Sair e trocar de usuário</a></p>';
    }

    $css = '<style>'.dash_minify_css('
:root{--dash-primary:#0a66c2;--dash-primary-dark:#084a8f;--dash-text:#1f2937;--dash-muted:#4b5563;}
.dash-login-embed{display:flex;justify-content:center;align-items:center;padding:56px 18px;background:linear-gradient(135deg,rgba(10,102,194,.08),rgba(17,24,39,.02));font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--dash-text);}
.dash-login-card{width:100%;max-width:540px;background:#fff;border:1px solid #d1d5db;border-radius:16px;box-shadow:0 22px 70px rgba(10,102,194,.16);padding:28px 28px 22px;}
.dash-login-eyebrow{display:inline-block;padding:6px 10px;margin:0 0 10px;font-size:.82rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--dash-primary);background:rgba(10,102,194,.08);border-radius:999px;}
.dash-login-card h2{margin:0 0 6px;font-size:1.45rem;font-weight:800;color:var(--dash-text);}
.dash-login-card p{margin:0 0 14px;color:var(--dash-muted);}
.dash-login-card form{margin-top:8px;}
.dash-login-form form{display:flex;flex-direction:column;gap:12px;}
.dash-login-card form p{margin:0;}
.dash-login-card label{font-weight:700;color:var(--dash-text);margin-bottom:4px;}
.dash-login-card input[type=text],.dash-login-card input[type=password]{width:100%;box-sizing:border-box;border:1px solid #d1d5db;border-radius:10px;padding:10px 12px;font-size:1rem;transition:border-color .15s ease,box-shadow .15s ease;}
.dash-login-card input[type=text]:focus,.dash-login-card input[type=password]:focus{border-color:var(--dash-primary);box-shadow:0 0 0 3px rgba(10,102,194,.18);outline:none;}
.dash-login-card .login-remember{display:flex;align-items:center;gap:8px;font-size:.95rem;color:var(--dash-text);}
.dash-login-card .login-remember label{display:flex;align-items:center;gap:8px;margin:0;font-weight:600;}
.dash-login-card .login-submit{margin-top:2px;}
.dash-login-card .login-submit .button-primary{width:100%;background:var(--dash-primary);border:1px solid var(--dash-primary);border-radius:10px;padding:11px 12px;font-size:1rem;font-weight:700;box-shadow:0 10px 24px rgba(10,102,194,.18);transition:transform .14s ease,box-shadow .14s ease,background .14s ease;}
.dash-login-card .login-submit .button-primary:hover{background:var(--dash-primary-dark);border-color:var(--dash-primary-dark);transform:translateY(-1px);box-shadow:0 16px 32px rgba(8,74,143,.2);}
.dash-login-card .login-submit .button-primary:focus{outline:none;box-shadow:0 0 0 3px rgba(10,102,194,.3);}
.dash-request-block{margin-top:16px;padding:16px 14px;border:1px solid #e5e7eb;border-radius:12px;background:#f9fafb;box-shadow:inset 0 1px 0 rgba(255,255,255,.8);}
.dash-request-block h3{margin:0 0 6px;font-size:1.05rem;color:var(--dash-text);}
.dash-request-hint{margin:0 0 10px;color:var(--dash-muted);}
.dash-request-status{font-weight:700;margin:0 0 8px;color:var(--dash-primary);}
.dash-request-sent{margin:0 0 8px;color:var(--dash-muted);font-weight:600;}
.dash-request-form{display:flex;flex-direction:column;gap:10px;}
.dash-request-form label{font-weight:700;color:var(--dash-text);}
.dash-request-form input[type=text]{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;transition:border-color .15s ease,box-shadow .15s ease;}
.dash-request-form input[type=text]:focus{border-color:var(--dash-primary);box-shadow:0 0 0 3px rgba(10,102,194,.18);outline:none;}
.dash-request-form .button-primary{align-self:flex-start;background:var(--dash-primary);border:1px solid var(--dash-primary);color:#fff;padding:10px 14px;border-radius:10px;font-weight:700;cursor:pointer;transition:transform .14s ease,box-shadow .14s ease,background .14s ease;}
.dash-request-form .button-primary:hover{background:var(--dash-primary-dark);border-color:var(--dash-primary-dark);transform:translateY(-1px);box-shadow:0 12px 26px rgba(10,102,194,.18);}
.dash-login-logout{margin-top:14px;text-align:center;}
.dash-login-logout a{color:var(--dash-primary);font-weight:700;text-decoration:none;}
.dash-login-logout a:hover{text-decoration:underline;}
@media (max-width:560px){
  .dash-login-card{padding:22px 18px;}
  .dash-login-card h2{font-size:1.3rem;}
}
    ').'</style>';

    $status_msg = '';
    if ($status === 'pending') {
        $status_msg = '';
    } elseif ($status === 'rejected') {
        $status_msg = '<p class="dash-request-status">Solicitação recusada. Você pode enviar uma nova solicitação.</p>';
    }

    $request_form = '';
    $status_nonce = $is_logged ? wp_create_nonce('dash_status_check') : '';

    if ($is_logged) {
        $u = wp_get_current_user();
        $prefill = $u ? $u->display_name : '';
        $action = esc_url(admin_url('admin-post.php'));
        $request_form = '
            <div class="dash-request-block" data-dash-status="'.esc_attr($status).'">
              <h3>Solicite aprovação para ver o dashboard</h3>
              <p class="dash-request-hint">Já estamos logados com sua conta. Envie seu nome para liberar o acesso.</p>
              '.$status_msg.'
              <div class="dash-request-sent" '.($status === 'pending' ? '' : 'style="display:none"').'>Solicitação enviada. Aguarde a aprovação do administrador.</div>
              <form class="dash-request-form" method="post" action="'.$action.'" data-dash-request="1" '.($status === 'pending' ? 'style="display:none"' : '').'>
                <input type="hidden" name="action" value="dash_request_access">
                <input type="hidden" name="dash_request_scope" value="'.esc_attr($scope_id).'">
                '.wp_nonce_field('dash_request_access', '_dashreq', true, false).'
                <label for="dash-request-name">Nome completo</label>
                <input type="text" name="dash_request_name" id="dash-request-name" value="'.esc_attr($prefill).'" required>
                <button type="submit" class="button button-primary">'.($status === 'rejected' ? 'Enviar nova solicitação' : 'Enviar solicitação').'</button>
              </form>
            </div>';
    }

    $login_block = $login_form ? '<div class="dash-login-form">'.$login_form.'</div>' : '';

    $poll_js = '';
    if ($is_logged) {
        $ajax = esc_url(admin_url('admin-ajax.php'));
        $poll_js = '
        <script>
        (function(){
          var blk = document.querySelector(".dash-request-block");
          if(!blk) return;
          var form = blk.querySelector(".dash-request-form");
          var sent = blk.querySelector(".dash-request-sent");
          var statusLabel = blk.querySelector(".dash-request-status");
          var current = blk.getAttribute("data-dash-status") || "";
          var scopeId = '.(int)$scope_id.';

          function apply(state){
            current = state;
            blk.setAttribute("data-dash-status", state);
            if(state === "approved"){
              if(statusLabel){ statusLabel.textContent = "Solicitação aprovada! Abrindo dashboard..."; statusLabel.style.display = ""; }
              if(sent){ sent.style.display = ""; sent.textContent = "Solicitação aprovada!"; }
              if(form){ form.style.display = "none"; }
              setTimeout(function(){ window.location.reload(); }, 800);
              return;
            }
            if(state === "pending"){
              if(statusLabel){ statusLabel.textContent = ""; statusLabel.style.display = "none"; }
              if(sent){ sent.style.display = ""; sent.textContent = "Solicitação enviada. Aguarde a aprovação do administrador."; }
              if(form){ form.style.display = "none"; }
              return;
            }
            if(state === "rejected"){
              if(statusLabel){ statusLabel.textContent = "Solicitação recusada. Você pode enviar novamente."; statusLabel.style.display = ""; }
              if(sent){ sent.style.display = "none"; }
              if(form){ form.style.display = ""; }
              return;
            }
            if(form){ form.style.display = ""; }
            if(sent){ sent.style.display = "none"; }
          }

          function poll(){
            var fd = new FormData();
            fd.append("action","dash_request_status");
            fd.append("scope_id", String(scopeId || ""));
            fd.append("_dashnonce","'.esc_js($status_nonce).'");
            fetch("'.$ajax.'",{method:"POST",credentials:"same-origin",body:fd})
              .then(function(r){ return r.json(); })
              .then(function(res){
                if(!res || !res.success || !res.data) return;
                var incoming = res.data.status || "";
                if(current === "pending" && incoming !== "approved" && incoming !== "rejected"){
                  return; // mantém estático enquanto pendente
                }
                if(incoming && incoming !== current){
                  apply(incoming);
                }
              })
              .catch(function(){});
          }

          if(form){
            form.addEventListener("submit", function(ev){
              ev.preventDefault();
              var btn = form.querySelector("button[type=\"submit\"]");
              var btnTxt = btn ? btn.textContent : "";
              if(btn){ btn.disabled = true; btn.textContent = "Enviando..."; }
              var fd = new FormData(form);
              fd.set("action","dash_request_access_ajax");
              fetch("'.$ajax.'",{method:"POST",credentials:"same-origin",body:fd})
                .then(function(r){ return r.json(); })
                .then(function(res){
                  if(res && res.success && res.data){
                    if(res.data.status){ apply(res.data.status); }
                    if(res.data.message && statusLabel){ statusLabel.textContent = res.data.message; statusLabel.style.display = ""; }
                  } else {
                    form.submit();
                  }
                })
                .catch(function(){ form.submit(); })
                .finally(function(){ if(btn){ btn.disabled = false; btn.textContent = btnTxt; } });
            });
          }

          if(current){ apply(current); }
          setInterval(poll, 8000);
        })();
        </script>';
    }

    return $css.'<div class="login dash-login-embed"><div class="dash-login-card"><span class="dash-login-eyebrow">Dashboard de assinaturas</span><h2>Acesso restrito</h2><p>'.$message.'</p>'.$login_block.$request_form.$logout_link.'</div></div>'.$poll_js;
}

/**
 * Processa solicitação de acesso (usuário não-admin).
 */
function dash_handle_request_access() {
    $redirect = dash_get_current_url();
    if (!is_user_logged_in()) {
        wp_safe_redirect(wp_login_url($redirect));
        exit;
    }
    if (!isset($_POST['_dashreq']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_dashreq'])), 'dash_request_access')) {
        wp_safe_redirect(add_query_arg('dash_req','fail',$redirect));
        exit;
    }
    $uid = get_current_user_id();
    $scope_id = isset($_POST['dash_request_scope']) ? (int)$_POST['dash_request_scope'] : 0;
    if (current_user_can('manage_options') || dash_is_user_whitelisted($uid, $scope_id)) {
        wp_safe_redirect(add_query_arg('dash_req','approved',$redirect));
        exit;
    }
    $name = isset($_POST['dash_request_name']) ? wp_unslash($_POST['dash_request_name']) : '';
    dash_add_pending_request($uid, $name, $scope_id);
    dash_set_request_status_meta($uid, 'pending');
    wp_safe_redirect(add_query_arg('dash_req','sent',$redirect));
    exit;
}
add_action('admin_post_dash_request_access','dash_handle_request_access');
add_action('admin_post_nopriv_dash_request_access','dash_handle_request_access');

/**
 * Processa decisão de acesso (admin).
 */
function dash_handle_decide_access() {
    if (!current_user_can('manage_options')) {
        wp_die('Acesso negado');
    }
    if (!isset($_POST['_dashdec']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_dashdec'])), 'dash_decide_access')) {
        wp_die('Nonce inválido');
    }
    $uid = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $scope_id = isset($_POST['scope_id']) ? (int)$_POST['scope_id'] : 0;
    $decision = isset($_POST['decision']) ? sanitize_text_field(wp_unslash($_POST['decision'])) : '';
    if ($uid > 0) {
        if ($decision === 'approve') {
            dash_approve_request($uid, $scope_id);
        } elseif ($decision === 'reject') {
            dash_reject_request($uid, $scope_id);
        }
    }
    $back = wp_get_referer() ?: home_url();
    wp_safe_redirect(add_query_arg('dash_req','done',$back));
    exit;
}
add_action('admin_post_dash_decide_access','dash_handle_decide_access');

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
 * Obtém a taxa de inscrição (signup fee) configurada no produto/assinatura.
 * Retorna float >= 0; 0 significa não configurada.
 */
function dash_get_subscription_signup_fee($product_id) {
    static $cache = [];
    $product_id = (int)$product_id;
    if (!$product_id) return 0.0;
    if (isset($cache[$product_id])) return $cache[$product_id];

    $fee = 0.0;
    $base_id = dash_maybe_get_parent_product_id($product_id);

    if (function_exists('wc_get_product') && class_exists('WC_Subscriptions_Product')) {
        try {
            $product_obj = wc_get_product($base_id);
            if ($product_obj) {
                $raw = \WC_Subscriptions_Product::get_sign_up_fee($product_obj);
                if ($raw !== '' && $raw !== null) {
                    $fee = max(0.0, (float)$raw);
                }
            }
        } catch (\Throwable $e) {}
    }

    if ($fee <= 0 && function_exists('get_post_meta')) {
        $meta_keys = ['_subscription_sign_up_fee','subscription_sign_up_fee','assinatura_taxa_inscricao','taxa_inscricao','inscricao_fee'];
        foreach ($meta_keys as $k) {
            $raw = get_post_meta($base_id, $k, true);
            if ($raw !== '' && $raw !== null) {
                $fee = max(0.0, (float)$raw);
                if ($fee > 0) break;
            }
        }
    }

    return $cache[$product_id] = $fee;
}

/**
 * Separa pagamentos que parecem ser taxa de inscrição para não contarem como parcelas.
 * Retorna ['payments'=>[], 'fees'=>[]]
 */
function dash_filter_signup_fee_payments(array $payments, float $signup_fee_amount = 0.0) {
    $out = ['payments'=>[],'fees'=>[]];
    $signup_fee_amount = ($signup_fee_amount > 0) ? (float)$signup_fee_amount : 0.0;

    foreach ($payments as $p) {
        $val = isset($p['value']) ? (float)$p['value'] : null;
        $desc = strtolower(trim((string)($p['description'] ?? '')));
        $charge = strtolower(trim((string)($p['chargeType'] ?? ($p['paymentType'] ?? ''))));
        $inst = (isset($p['installmentNumber']) && is_numeric($p['installmentNumber'])) ? (int)$p['installmentNumber'] : null;

        $desc_is_fee = ($desc !== '' && preg_match('/taxa\s+de\s+inscri[cç][ãa]o|inscri[cç][ãa]o\s+.*taxa|matr[ií]cula/', $desc));
        $charge_is_fee = in_array($charge, ['signupfee','taxa_inscricao','enrollment_fee','matricula','signup'], true);
        $value_matches_fee = ($signup_fee_amount > 0 && $val !== null && abs($val - $signup_fee_amount) < 0.01);
        $inst_hint_fee = ($inst !== null && $inst <= 0);

        $is_fee = $charge_is_fee
            || ($desc_is_fee && ($inst_hint_fee || $value_matches_fee || $charge_is_fee))
            || ($value_matches_fee && ($inst_hint_fee || $charge_is_fee))
            || ($inst_hint_fee && $desc_is_fee);

        if ($is_fee) {
            $out['fees'][] = $p;
            continue;
        }
        $out['payments'][] = $p;
    }

    return $out;
}

function dash_get_product_name($product_id){
    $product_id = (int)$product_id;
    if (!$product_id) return '';
    $base_id = dash_maybe_get_parent_product_id($product_id);

    $name = '';
    if (function_exists('wc_get_product')) {
        $p = wc_get_product($base_id);
        if ($p) $name = $p->get_name();
    }
    if (!$name) $name = get_post_field('post_title', $base_id);
    if (!$name) $name = 'Produto #'.$base_id;
    return $name;
}

/**
 * Minify CSS snippets generated on the fly.
 */
function dash_minify_css($css) {
    if (!is_string($css) || $css === '') {
        return '';
    }
    $css = preg_replace('!/\*.*?\*/!s', '', $css);
    $css = preg_replace('/\s*([{};:,])\s*/', '$1', $css);
    $css = preg_replace('/;}/', '}', $css);
    $css = preg_replace('/\s+/', ' ', $css);
    return trim($css);
}

/**
 * Light JS minifier (keeps semantics; trims trailing whitespace/blank lines).
 */
function dash_minify_js($js) {
    if (!is_string($js) || $js === '') {
        return '';
    }
    $js = preg_replace('/[ \t]+(\r?\n)/', '$1', $js);
    $js = preg_replace('/(\r?\n){2,}/', "\n", $js);
    return trim($js);
}

/**
 * Acesso extra: whitelist e solicitações.
 */
function dash_get_whitelist() {
    $list = get_option('dash_access_whitelist');
    return is_array($list) ? $list : [];
}

/**
 * Renderiza cards de solicitações pendentes (admin).
 */
function dash_render_request_cards(array $pending, $action_url, $nonce_field, $show_empty = false) {
    $html = '';
    foreach ($pending as $req) {
        $uid = (int)($req['user_id'] ?? 0);
        $scope = (int)($req['scope_id'] ?? 0);
        $nm  = $req['name'] ?? '';
        $em  = $req['email'] ?? '';
        $lg  = $req['login'] ?? '';
        $ts  = !empty($req['time']) ? wp_date('d/m/Y H:i', (int)$req['time']) : '';
        $scope_label = 'Dashboard geral';
        if ($scope > 0) {
            $base = dash_maybe_get_parent_product_id($scope);
            $scope_name = dash_get_product_name($base ?: $scope);
            $scope_label = 'Curso: '.$scope_name;
        }
        $html .= '<div class="dash-request-card"><div class="dash-request-meta"><strong>'.esc_html($nm ?: 'Solicitante sem nome').'</strong><div>'.esc_html($em ?: 'Sem e-mail').' · '.esc_html($lg ?: 'sem login').'</div><div>'.esc_html($scope_label).'</div><div>Enviado em '.esc_html($ts ?: '—').'</div></div><div class="dash-request-actions">';
        $html .= '<form method="post" action="'.esc_url($action_url).'">'.$nonce_field.'<input type="hidden" name="action" value="dash_decide_access"><input type="hidden" name="user_id" value="'.esc_attr($uid).'"><input type="hidden" name="scope_id" value="'.esc_attr($scope).'"><input type="hidden" name="decision" value="approve"><button type="submit" class="button button-primary">Aceitar</button></form>';
        $html .= '<form method="post" action="'.esc_url($action_url).'">'.$nonce_field.'<input type="hidden" name="action" value="dash_decide_access"><input type="hidden" name="user_id" value="'.esc_attr($uid).'"><input type="hidden" name="scope_id" value="'.esc_attr($scope).'"><input type="hidden" name="decision" value="reject"><button type="submit" class="button">Recusar</button></form>';
        $html .= '</div></div>';
    }
    if ($html === '' && $show_empty) {
        $html = '<div class="dash-requests-empty">Nenhuma solicitação pendente.</div>';
    }
    return $html;
}
function dash_is_user_whitelisted($user_id, $scope_id = 0, $alt_scope_id = 0) {
    $user_id = (int)$user_id;
    $scope_id = (int)$scope_id;
    $alt_scope_id = (int)$alt_scope_id;
    if (!$user_id) return false;
    $list = dash_get_whitelist();
    if (!array_key_exists($user_id, $list)) return false;
    $entry = $list[$user_id];

    // compat: valor booleano antigo = acesso geral
    if ($entry === true) return true;

    if (!is_array($entry)) return false;

    if (!empty($entry['general'])) return true;

    $ids = array_map('intval', (array)($entry['ids'] ?? []));

    if ($scope_id > 0 && in_array($scope_id, $ids, true)) return true;
    if ($alt_scope_id > 0 && $alt_scope_id !== $scope_id && in_array($alt_scope_id, $ids, true)) return true;

    return false;
}
function dash_add_to_whitelist($user_id, $scope_id = 0) {
    $user_id = (int)$user_id;
    $scope_id = (int)$scope_id;
    if (!$user_id) return;
    $list = dash_get_whitelist();

    // acesso geral
    if ($scope_id <= 0) {
        $list[$user_id] = ['general' => true];
        update_option('dash_access_whitelist', $list, false);
        return;
    }

    $entry = $list[$user_id] ?? [];
    if ($entry === true) { // compatibilidade
        $entry = ['general' => true];
    }
    if (!is_array($entry)) $entry = [];
    $ids = array_map('intval', isset($entry['ids']) && is_array($entry['ids']) ? $entry['ids'] : []);
    if (!in_array($scope_id, $ids, true)) {
        $ids[] = $scope_id;
    }
    $entry['ids'] = $ids;
    $list[$user_id] = $entry;
    update_option('dash_access_whitelist', $list, false);
}
function dash_remove_from_whitelist($user_id, $scope_id = 0) {
    $user_id = (int)$user_id;
    $scope_id = (int)$scope_id;
    if (!$user_id) return;
    $list = dash_get_whitelist();
    if (!isset($list[$user_id])) return;

    if ($scope_id <= 0 || $list[$user_id] === true) {
        unset($list[$user_id]);
        update_option('dash_access_whitelist', $list, false);
        return;
    }

    $entry = $list[$user_id];
    if (!is_array($entry)) {
        unset($list[$user_id]);
        update_option('dash_access_whitelist', $list, false);
        return;
    }

    $ids = array_map('intval', isset($entry['ids']) && is_array($entry['ids']) ? $entry['ids'] : []);
    $ids = array_values(array_filter($ids, function($id) use ($scope_id){ return (int)$id !== $scope_id; }));
    $entry['ids'] = $ids;

    if (empty($ids) && empty($entry['general'])) {
        unset($list[$user_id]);
    } else {
        $list[$user_id] = $entry;
    }
    update_option('dash_access_whitelist', $list, false);
}
function dash_get_pending_requests() {
    $req = get_option('dash_access_requests');
    return is_array($req) ? $req : [];
}
function dash_save_pending_requests(array $reqs) {
    update_option('dash_access_requests', $reqs, false);
}
function dash_user_has_pending_for_scope($user_id, $scope_id = 0) {
    $user_id = (int)$user_id;
    $scope_id = (int)$scope_id;
    if (!$user_id) return false;
    foreach (dash_get_pending_requests() as $p) {
        if ((int)($p['user_id'] ?? 0) === $user_id && (int)($p['scope_id'] ?? 0) === $scope_id) {
            return true;
        }
    }
    return false;
}
function dash_set_request_status_meta($user_id, $status) {
    $user_id = (int)$user_id;
    if (!$user_id) return;
    update_user_meta($user_id, 'dash_request_status', $status);
    update_user_meta($user_id, 'dash_request_updated', time());
}
function dash_get_request_status_meta($user_id) {
    $user_id = (int)$user_id;
    if (!$user_id) return ['status'=>'', 'updated'=>0];
    return [
        'status'  => (string)get_user_meta($user_id, 'dash_request_status', true),
        'updated' => (int)get_user_meta($user_id, 'dash_request_updated', true),
    ];
}
function dash_add_pending_request($user_id, $name, $scope_id = 0) {
    $user_id = (int)$user_id;
    $scope_id = (int)$scope_id;
    if (!$user_id) return;
    $pending = dash_get_pending_requests();
    foreach ($pending as $p) {
        if ((int)($p['user_id'] ?? 0) === $user_id && (int)($p['scope_id'] ?? 0) === $scope_id) {
            return;
        }
    }
    $u = get_userdata($user_id);
    $pending[] = [
        'user_id' => $user_id,
        'scope_id'=> $scope_id,
        'name'    => sanitize_text_field($name ?: ($u ? $u->display_name : '')),
        'login'   => $u ? $u->user_login : '',
        'email'   => $u ? $u->user_email : '',
        'time'    => current_time('timestamp'),
    ];
    dash_save_pending_requests($pending);
}
function dash_approve_request($user_id, $scope_id = 0) {
    $user_id = (int)$user_id;
    $scope_id = (int)$scope_id;
    if (!$user_id) return;
    $pending = dash_get_pending_requests();
    $pending = array_values(array_filter($pending, function($r) use ($user_id, $scope_id){ return (int)($r['user_id'] ?? 0) !== $user_id || (int)($r['scope_id'] ?? 0) !== $scope_id; }));
    dash_save_pending_requests($pending);
    dash_add_to_whitelist($user_id, $scope_id);
    dash_set_request_status_meta($user_id, 'approved');
}
function dash_reject_request($user_id, $scope_id = 0) {
    $user_id = (int)$user_id;
    $scope_id = (int)$scope_id;
    if (!$user_id) return;
    $pending = dash_get_pending_requests();
    $pending = array_values(array_filter($pending, function($r) use ($user_id, $scope_id){ return (int)($r['user_id'] ?? 0) !== $user_id || (int)($r['scope_id'] ?? 0) !== $scope_id; }));
    dash_save_pending_requests($pending);
    dash_remove_from_whitelist($user_id, $scope_id);
    dash_set_request_status_meta($user_id, 'rejected');
}

/**
 * AJAX: retorna status da solicitação de acesso para o usuário logado.
 */
function dash_ajax_request_status() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message'=>'auth']);
    }
    if (!isset($_POST['_dashnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_dashnonce'])), 'dash_status_check')) {
        wp_send_json_error(['message'=>'nonce']);
    }
    $uid = get_current_user_id();
    $scope = isset($_POST['scope_id']) ? (int)$_POST['scope_id'] : 0;
    $scope_base = $scope ? dash_maybe_get_parent_product_id($scope) : 0;
    $allowed = dash_is_user_whitelisted($uid, $scope, $scope_base);
    $meta = dash_get_request_status_meta($uid);
    $status = $meta['status'] ?? '';

    $pending_scope = dash_user_has_pending_for_scope($uid, $scope);
    if ($allowed) {
        $status = 'approved';
    } elseif ($pending_scope) {
        $status = 'pending';
    } else {
        $status = '';
    }

    wp_send_json_success([
        'status'  => $status,
        'allowed' => $allowed ? 1 : 0,
        'message' => $allowed ? '' : 'Sua conta ainda não tem permissão para este dashboard.',
    ]);
}
add_action('wp_ajax_dash_request_status','dash_ajax_request_status');
add_action('wp_ajax_nopriv_dash_request_status', function(){ wp_send_json_error(['message'=>'auth']); });

/**
 * AJAX: pendências em tempo real (somente admin).
 */
function dash_ajax_pending_requests() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message'=>'auth']);
    }
    if (!isset($_POST['_dashreqnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_dashreqnonce'])), 'dash_pending_requests')) {
        wp_send_json_error(['message'=>'nonce']);
    }
    $pending = dash_get_pending_requests();
    $action = admin_url('admin-post.php');
    $cards = dash_render_request_cards($pending, $action, wp_nonce_field('dash_decide_access','_dashdec',true,false), true);
    wp_send_json_success([
        'html'  => $cards,
        'count' => count($pending),
    ]);
}
add_action('wp_ajax_dash_pending_requests','dash_ajax_pending_requests');

/**
 * AJAX: cria/atualiza solicitação de acesso sem redirecionar.
 */
function dash_ajax_request_access() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message'=>'auth']);
    }
    if (!isset($_POST['_dashreq']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_dashreq'])), 'dash_request_access')) {
        wp_send_json_error(['message'=>'nonce']);
    }
    $uid = get_current_user_id();
    $scope_id = isset($_POST['dash_request_scope']) ? (int)$_POST['dash_request_scope'] : 0;
    if (current_user_can('manage_options') || dash_is_user_whitelisted($uid, $scope_id)) {
        dash_set_request_status_meta($uid, 'approved');
        wp_send_json_success(['status'=>'approved','message'=>'Solicitação aprovada!']);
    }

    $name = isset($_POST['dash_request_name']) ? wp_unslash($_POST['dash_request_name']) : '';
    dash_add_pending_request($uid, $name, $scope_id);
    dash_set_request_status_meta($uid, 'pending');
    wp_send_json_success(['status'=>'pending','message'=>'Solicitação enviada. Aguarde a aprovação do administrador.']);
}
add_action('wp_ajax_dash_request_access_ajax','dash_ajax_request_access');

/**
 * Registro básico de assets (handles).
 */
function dash_register_assets() {
    static $registered = false;
    if ($registered) return;

    $ver = '1.20';
    wp_register_style('dash-colab-dashboard', false, [], $ver);
    wp_register_script('dash-colab-dashboard', false, ['jquery'], $ver, true);

    // CSS do login do WordPress para reaproveitar o visual padrão.
    wp_register_style('dash-colab-login', includes_url('css/login.min.css'), [], get_bloginfo('version'));

    $registered = true;
}

/**
 * Enfileira CSS do login incorporado.
 */
function dash_enqueue_login_assets() {
    dash_register_assets();
    wp_enqueue_style('dash-colab-login');
}

/**
 * Constrói (sem <style>) todo o CSS do dashboard, mantendo o conteúdo original.
 */
function dash_build_dashboard_css() {
    $css_source = <<<'CSS'
:root{--dash-primary:#0a66c2;--dash-primary-dark:#084a8f;--dash-text:#1f2937;--dash-muted:#6b7280;--dash-border:#e5e7eb;--paid:#16a34a;--overdue:#e11d48;--pending:#f59e0b;--future:#e5e7eb;--box-border:rgba(0,0,0,.35);}
.dash-wrap{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,"Apple Color Emoji","Segoe UI Emoji";color:var(--dash-text);}
.dash-h3{margin:24px 0 8px;font-size:1.125rem;font-weight:700;}
.dash-table{width:100%;min-width:720px;border-collapse:separate;border-spacing:0;margin:8px 0 24px;font-size:.95rem;box-shadow:0 1px 2px rgba(0,0,0,.04);border:2px solid #000;border-radius:10px;overflow:visible;}
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
.dash-table tbody tr.row-tooltip-suspended::after,.dash-table tbody tr.row-tooltip-suspended::before{display:none!important;}
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
.dash-hist-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 8px;}
.dash-hist-row .dash-input{flex:0 0 190px;max-width:220px;}
.dash-legend{display:flex;gap:14px;align-items:center;margin:4px 0 10px;flex-wrap:wrap;font-size:.9rem;color:#374151;}
.dash-table-scroll{width:100%;-webkit-overflow-scrolling:touch;margin-bottom:18px;}
.dash-table-scroll::-webkit-scrollbar{height:6px;}
.dash-table-scroll::-webkit-scrollbar-thumb{background:rgba(0,0,0,.25);border-radius:6px;}
.dash-search .dash-btn,.dash-hist-row .dash-btn{flex:0 0 auto;}
@media (max-width:980px){
  .dash-summary{grid-template-columns:repeat(2,1fr);}
  .dash-table{font-size:.9rem;}
  .dash-table th,.dash-table td{padding:9px 6px;}
  .dash-table td:nth-child(4){min-width:200px;}
}
@media (max-width:720px){
  .dash-summary{grid-template-columns:1fr;}
  .dash-search,.dash-hist-row{flex-direction:column;align-items:stretch;}
  .dash-input,.dash-select,.dash-btn{flex:1 1 auto;max-width:100%;}
  .dash-btn{width:100%;}
  .dash-table{min-width:640px;}
  .dash-legend{flex-direction:column;align-items:flex-start;gap:8px;font-size:1rem;}
  .dash-hist-row .dash-input{flex:1 1 auto;}
  .dash-kpi .dash-kpi-perc{font-size:1.5rem;}
  .status-box{width:16px;height:16px;}
}
@media (max-width:540px){
  .dash-table{min-width:560px;font-size:.85rem;}
  .dash-table th,.dash-table td{padding:8px 5px;}
  .dash-wrap{padding:0 6px;}
  .dash-title,.dash-locked-course{text-align:center;}
  .dash-search{gap:6px;}
  .dash-hist-row{gap:6px;}
  .dash-hist-banner{font-size:.85rem;}
  .dash-btn{padding:10px 12px;}
}
CSS;
    $css = dash_minify_css($css_source);

    $css_titles = <<<'CSS'
.dash-title{margin:0 0 4px;font-weight:800;font-size:1.25rem;}
.dash-locked-course{margin:0 0 12px;color:#374151;font-size:.98rem;}
.dash-locked-course strong{font-weight:700;}
CSS;
    $css .= "\n" . dash_minify_css($css_titles);

    $css_extra_source = <<<'CSS'
/* Esconde parcelas fora do mês escolhido e/ou a linha inteira quando aplicável */
.month-dim-hide{display:none !important;}
.month-filter-hide{display:none !important;}
/* Datepicker em modo mês/ano (esconde os dias) */
.ui-datepicker .ui-datepicker-calendar{display:none;}
.ui-datepicker .ui-datepicker-current{display:none;}
.ui-state-disabled{opacity:.45;pointer-events:none;}
.status-separator{display:inline-block;padding:0 6px;color:var(--dash-muted);font-weight:700;}
.status-box.signup-fee{box-shadow:inset 0 0 0 1px rgba(0,0,0,.08);}
.dash-requests{margin:0 0 16px;padding:14px 12px;border:1px solid #e5e7eb;border-radius:12px;background:linear-gradient(135deg,rgba(10,102,194,.08),#fff);box-shadow:0 10px 26px rgba(0,0,0,.08);}
.dash-requests h3{margin:0 0 10px;font-size:1.05rem;}
.dash-request-card{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 8px;border-top:1px solid #e5e7eb;}
.dash-request-card:first-of-type{border-top:0;padding-top:0;}
.dash-request-meta{font-size:.95rem;}
.dash-request-meta strong{display:block;font-size:1rem;}
.dash-request-actions form{display:inline-block;margin-left:6px;}
.dash-request-actions button{cursor:pointer;}
.dash-request-actions .button-primary{background:var(--dash-primary);border-color:var(--dash-primary);color:#fff;}
.dash-request-actions .button-primary:hover{background:var(--dash-primary-dark);border-color:var(--dash-primary-dark);}
.dash-requests-empty{padding:10px 8px;color:#6b7280;}
.dash-user-icon-wrap{display:flex;align-items:center;gap:8px;margin:0 0 8px;}
.dash-user-icon{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:12px;background:rgba(10,102,194,.12);border:1px solid rgba(10,102,194,.22);box-shadow:0 6px 18px rgba(0,0,0,.08);cursor:pointer;color:var(--dash-primary);font-weight:800;}
.dash-user-icon span{font-size:18px;line-height:1;}
.dash-accepted{position:relative;display:inline-block;}
.dash-accepted details{position:relative;}
.dash-accepted summary{list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid #d1d5db;border-radius:12px;background:#fff;box-shadow:0 8px 20px rgba(0,0,0,.08);font-weight:700;color:var(--dash-text);}
.dash-accepted summary::-webkit-details-marker{display:none;}
.dash-accepted summary:hover{border-color:var(--dash-primary);color:var(--dash-primary);}
.dash-accepted-list{left:-1rem;top:3.8rem;position:absolute;z-index:50;right:0;min-width:320px;width:clamp(320px,90vw,520px);max-width:clamp(320px,90vw,520px);background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 18px 42px rgba(0,0,0,.16);padding:14px 14px 12px;transform:none;}
.dash-accepted-close{border:0;background:transparent;color:#6b7280;font-size:18px;font-weight:800;cursor:pointer;line-height:1;border-radius:8px;width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;}
.dash-accepted-close:hover{color:var(--dash-primary);background:rgba(10,102,194,.08);}
.dash-accepted-header{display:flex;align-items:center;gap:10px;margin:0 0 10px;}
.dash-accepted-header h4{margin:0;font-size:1rem;}
.dash-accepted-search{flex:1 1 auto;padding:8px 10px;border:1px solid #d1d5db;border-radius:10px;font-size:.92rem;transition:border-color .12s ease,box-shadow .12s ease;}
.dash-accepted-search:focus{outline:none;border-color:var(--dash-primary);box-shadow:0 0 0 3px rgba(10,102,194,.16);}
.dash-accepted-body{border:1px solid #e5e7eb;border-radius:12px;padding:6px;background:#f9fafb;max-height:calc(3 * 46px);overflow-y:auto;}
.dash-accepted-item{border-bottom:1px solid #e5e7eb;}
.dash-accepted-item:last-child{border-bottom:0;}
.dash-accepted-accordion{margin:0;}
.dash-accepted-accordion summary{list-style:none;display:flex;flex-direction:column;gap:4px;padding:10px 6px;cursor:pointer;align-items:flex-start;}
.dash-accepted-accordion summary::-webkit-details-marker{display:none;}
.dash-accepted-name{display:flex;align-items:flex-start;gap:6px;flex-wrap:wrap;}
.dash-accepted-name strong{font-size:.95rem;}
.dash-accepted-name small{color:#4b5563;font-size:.86rem;}
.dash-accepted-access{color:#4b5563;font-size:.82rem;}
.dash-accepted-scopes{padding:0 6px 10px 6px;display:flex;flex-direction:column;gap:6px;}
.dash-accepted-scope{display:flex;align-items:center;gap:10px;}
.dash-accepted-scope-name{flex:1 1 auto;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.9rem;}
.dash-accepted-form{margin:0;flex-shrink:0;}
.dash-accepted-revoke{background:#fff;border:1px solid #e11d48;color:#e11d48;border-radius:999px;padding:6px 12px;font-weight:700;cursor:pointer;white-space:nowrap;transition:background .14s ease,color .14s ease,transform .12s ease,box-shadow .14s ease;}
.dash-accepted-revoke:hover{background:#e11d48;color:#fff;transform:translateY(-1px);box-shadow:0 10px 20px rgba(225,29,72,.2);}
CSS;
    $css .= "\n" . dash_minify_css($css_extra_source);

    return trim($css);
}

/**
 * Constrói (sem <script>) todo o JS do dashboard, mantendo o conteúdo original.
 */
function dash_build_dashboard_js() {
    ob_start();
    ?>
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

  function resetMonthPicker(){
    var mon = $id('dash-month-picker');
    if(!mon) return;
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
  }
  window.dashResetMonthPicker = resetMonthPicker;

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
    var rows=t.querySelectorAll('tbody tr[id^=\"sub-\"]');
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
    var pDia=pct(visDia,visTotal), pAtr=pct(visAtr,visTotal), pCan=pct(visCan,visTotal);

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

    var note = $id('dash-summary-note');
    if (note) {
      var totalBase = parseInt(note.getAttribute('data-total') || '0', 10) || visTotal;
      note.textContent = 'Mostrando ' + visTotal + ' de ' + totalBase + ' assinaturas';
    }
  }
  window.updateSummary = updateSummary;

  function setRowTooltips(courseMap){
    var rows=document.querySelectorAll('.dash-table tbody tr[id^=\"sub-\"]');
    rows.forEach(function(tr){
      var cid=String(tr.getAttribute('data-course-id')||'');
      var cbase=String(tr.getAttribute('data-course-base-id')||'');
      var name=(courseMap[String(cid)]||'')||(courseMap[String(cbase)]||'');
      if(name){ tr.setAttribute('data-tooltip','Curso: '+name); tr.removeAttribute('title'); }
      else{ tr.removeAttribute('data-tooltip'); tr.removeAttribute('title'); }

      // Evita que o tooltip da linha apareça quando o foco está na status-box
      tr.querySelectorAll('.status-box').forEach(function(box){
        if(box.dataset.courseTipBound==='1') return;
        var suspend=function(){ tr.classList.add('row-tooltip-suspended'); };
        var resume=function(){ tr.classList.remove('row-tooltip-suspended'); };
        box.addEventListener('mouseenter', suspend);
        box.addEventListener('mouseleave', resume);
        box.addEventListener('focus', suspend);
        box.addEventListener('blur', resume);
        box.dataset.courseTipBound='1';
      });
    });
  }

  var ROWS = [];

  // ====== FILTRO principal (mês por CRIAÇÃO) ======
  function applyFilter(){
    ROWS = [].slice.call(document.querySelectorAll('.dash-table tbody tr[id^=\"sub-\"]'));

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

  resetMonthPicker();

  // limpa efeitos visuais e exibe todas as linhas
  ROWS = [].slice.call(document.querySelectorAll('.dash-table tbody tr[id^=\"sub-\"]'));
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
    return [].slice.call(document.querySelectorAll('.dash-table tbody tr[id^=\"sub-\"]'))
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
    var map = {};
    var s = document.getElementById('dash-course-filter');
    if (s){
      for (var i=0;i<s.options.length;i++){
        var opt = s.options[i];
        map[String(opt.value || '')] = opt.textContent || opt.innerText || opt.text || '';
      }
    }
    // Completa com o curso travado (quando o select não existe/está oculto)
    var lm = getLockedMeta();
    if (lm.locked){
      if (lm.id)     map[lm.id] = lm.name || map[lm.id] || '';
      if (lm.baseId) map[lm.baseId] = lm.name || map[lm.baseId] || '';
    }
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
    var groups = {};        
    var maxBoxesByCourse={}; 
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

    var sel = document.getElementById('dash-course-filter');
    var inp = document.getElementById('dash-search-input');
    var mon = document.getElementById('dash-month-picker');
    var his = document.getElementById('dash-snapshot-date');
    var histOn = !!window.__dashHistActive;

    // Snapshot das métricas visíveis no momento da exportação
    var visDia = countVisibleRows('dash-table-em_dia');
    var visAtr = countVisibleRows('dash-table-em_atraso');
    var visCan = countVisibleRows('dash-table-canceladas');
    var visTot = visDia + visAtr + visCan;
    function pctSafe(part){ return visTot ? Math.round((part/visTot)*100) : 0; }
    var pctDia = pctSafe(visDia);
    var pctAtr = pctSafe(visAtr);
    var pctCan = pctSafe(visCan);

    var summaryTableHtml = `
      <table>
        <tr><th>Status</th><th>Quantidade</th><th>Percentual</th></tr>
        <tr style="background:#0a66c2;color:#fff"><td>Total de assinaturas</td><td>${visTot}</td><td>${visTot>0?'100%':'0%'}</td></tr>
        <tr style="background:#16a34a;color:#fff"><td>Em Dia</td><td>${visDia}</td><td>${pctDia}%</td></tr>
        <tr style="background:#e11d48;color:#fff"><td>Em Atraso</td><td>${visAtr}</td><td>${pctAtr}%</td></tr>
        <tr style="background:#111;color:#fff"><td>Canceladas</td><td>${visCan}</td><td>${pctCan}%</td></tr>
      </table>
      <br/>
    `;

    // NOVO: usa o meta do curso travado se existir
    var lm = getLockedMeta();
    var selectedCourseLabel = 'Todos';
    if (lm.locked){
      selectedCourseLabel = lm.name || ('#' + (lm.baseId || lm.id));
    } else if (sel && sel.options[sel.selectedIndex]){
      selectedCourseLabel = sel.options[sel.selectedIndex].text || 'Todos';
    }

    var contextHtml = `
      <table>
        <tr><td colspan="3"><strong>Relatório do Dashboard (itens visíveis)</strong></td></tr>
        <tr><td>Curso selecionado:</td><td colspan="2">${selectedCourseLabel}</td></tr>
        <tr><td>Busca:</td><td colspan="2">${inp ? (inp.value || '—') : '—'}</td></tr>
        <tr><td>Mês (Criação):</td><td colspan="2">${mon ? (mon.value || '—') : '—'}</td></tr>
        <tr><td>Histórico:</td><td colspan="2">${histOn ? (his && his.value ? his.value.split('-').reverse().join('/') : 'Ativo') : 'Inativo'}</td></tr>
        <tr><td>Assinaturas visíveis:</td><td colspan="2">${visTot}</td></tr>
      </table>
      <br/>
      ${summaryTableHtml}
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

    function getLockedMeta(){
    var el = document.getElementById('dash-locked-meta');
    if(!el) return { locked:false, id:'', baseId:'', name:'' };
    return {
      locked: el.getAttribute('data-locked') === '1',
      id: String(el.getAttribute('data-course-id') || ''),
      baseId: String(el.getAttribute('data-course-base-id') || ''),
      name: el.getAttribute('data-course-name') || ''
    };
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
    ROWS = [].slice.call(document.querySelectorAll('.dash-table tbody tr[id^=\"sub-\"]'));
    updateSummary();

  }
  window.dashClearFilter = clearFilter; 

  onReady(init);
})();

(function(){
  function normalize(s){ return (s||'').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim(); }

  function clampAcceptedToViewport(panel){
    if (!panel) return;
    panel.style.transform = 'translateX(0)';
    var rect = panel.getBoundingClientRect();
    var vw = Math.max(window.innerWidth || 0, document.documentElement.clientWidth || 0);
    var margin = 12;
    var shift = 0;

    if (rect.right > vw - margin) {
      shift = rect.right - (vw - margin);
    }
    if ((rect.left - shift) < margin) {
      shift = rect.left - margin;
    }
    if (shift !== 0) {
      panel.style.transform = 'translateX(' + (-shift) + 'px)';
    }
  }

  function bindAcceptedSearch(){
    var list = document.querySelector('.dash-accepted-list');
    if (!list) return;
    var input = list.querySelector('.dash-accepted-search');
    var items = list.querySelectorAll('.dash-accepted-item');
    if (!input || !items.length) return;

    function apply(){
      var q = normalize(input.value);
      items.forEach(function(it){
        var nm = normalize(it.getAttribute('data-name') || it.textContent || '');
        var em = normalize(it.getAttribute('data-email') || '');
        var show = !q || nm.indexOf(q) > -1 || em.indexOf(q) > -1;
        it.style.display = show ? '' : 'none';
      });
      clampAcceptedToViewport(list);
    }

    var details = list.closest('details');
    function adjust(){ clampAcceptedToViewport(list); }
    if (details){
      details.addEventListener('toggle', function(){ if(details.open){ adjust(); } }, { passive:true });
    }
    window.addEventListener('resize', adjust);

    input.addEventListener('input', apply);
    apply();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindAcceptedSearch, { once:true });
  } else {
    bindAcceptedSearch();
  }
})();

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

    var rows = document.querySelectorAll('.dash-table tbody tr[id^=\"sub-\"]');
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


(function(){
  // Month picker nativo com validação de meses permitidos
  function pad2(n){ return (n<10?'0':'')+n; }

  function collectAllowedMonthsByCreation(){
    var set = new Set();
    document.querySelectorAll('.status-box').forEach(function(box){
      var br = box.getAttribute('data-de-criacao') || '';
      if (br) {
        var mb = String(br).match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/);
        if (mb) {
          set.add(mb[3] + '-' + pad2(parseInt(mb[2],10)));
          return;
        }
      }
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

  function initNativeMonthPicker(){
    var inp = document.getElementById('dash-month-picker');
    if (!inp) return;

    var allowed = collectAllowedMonthsByCreation();
    if (!allowed.length) return;

    var allowedSet = new Set(allowed);
    var minYm = allowed[0], maxYm = allowed[allowed.length-1];
    if (!inp.value) {
      // Mostra faixa válida no placeholder (quando suportado)
      inp.placeholder = 'De ' + minYm.slice(5,7) + '/' + minYm.slice(0,4) + ' a ' + maxYm.slice(5,7) + '/' + maxYm.slice(0,4);
    }

    // Força input month nativo
    try {
      inp.type = 'month';
      inp.min  = minYm;
      inp.max  = maxYm;
    } catch(e){}

    function clamp(){
      var v = (inp.value || '').trim();
      if (!v) return;
      if (!allowedSet.has(v)) {
        inp.value = '';
        return;
      }
      if (v < minYm) inp.value = minYm;
      if (v > maxYm) inp.value = maxYm;
    }
    inp.addEventListener('change', clamp);
    inp.addEventListener('input', clamp);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNativeMonthPicker, { once:true });
  } else {
    initNativeMonthPicker();
  }
})();

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
    if (!box.dataset || box.dataset._histcap !== '1') return;
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

    document.querySelectorAll('.dash-table tbody tr[id^=\"sub-\"]').forEach(function(tr){
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
    document.querySelectorAll('.dash-table tbody tr[id^=\"sub-\"]').forEach(function(tr){
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

(function(){
  function $id(id){ return document.getElementById(id); }

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
    if (typeof window.dashResetMonthPicker === 'function') {
      window.dashResetMonthPicker();
    } else {
      var mon = $id('dash-month-picker');
      if (mon) {
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
      }
    }

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

(function(){
  function $id(id){ return document.getElementById(id); }
  function toISO(brOrIso){
    var s = String(brOrIso||'').trim();
    var m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/); // dd/mm/aaaa
    if (m){
      var dd=('0'+parseInt(m[1],10)).slice(-2);
      var mm=('0'+parseInt(m[2],10)).slice(-2);
      return m[3]+'-'+mm+'-'+dd;
    }
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;        // aaaa-mm-dd
    return '';
  }
  function todayISO(){
    var d=new Date();
    var mm=('0'+(d.getMonth()+1)).slice(-2);
    var dd=('0'+d.getDate()).slice(-2);
    return d.getFullYear()+'-'+mm+'-'+dd;
  }
  function findMinDateFromBoxes(){
    var min = null;
    document.querySelectorAll('.status-box').forEach(function(box){
      // procura nos ISO primeiro
      ['data-created-ymd','data-due-ymd','data-paid-ymd','data-de-criacao','data-de-vencimento','data-de-pagamento'].forEach(function(attr){
        var raw = box.getAttribute(attr) || '';
        var iso = toISO(raw);
        if (iso){
          if (!min || iso < min){ min = iso; }
        }
      });
    });
    return min; // ex.: '2025-03-04' ou null
  }

  function setHistDateLimits(){
    var inp = $id('dash-snapshot-date');
    if (!inp) return;

    var minISO = findMinDateFromBoxes();
    var maxISO = todayISO();

    if (!minISO){ 
      // Nenhuma data encontrada nas parcelas → não limitar
      return;
    }

    // Define limites no input[type=date]
    inp.setAttribute('min', minISO);
    inp.setAttribute('max', maxISO);

    // Placeholder informativo (opcional)
    var minBR = minISO.split('-').reverse().join('/');
    var maxBR = maxISO.split('-').reverse().join('/');
    if (!inp.value){
      inp.placeholder = 'De ' + minBR + ' a ' + maxBR;
    }

    // Validação/normalização quando o usuário muda o valor
    function clampValue(){
      var v = (inp.value || '').trim();
      if (!v) return;
      if (v < minISO) inp.value = minISO;
      if (v > maxISO) inp.value = maxISO;
    }
    inp.addEventListener('change', clampValue);
    inp.addEventListener('input', clampValue);
  }

  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', setHistDateLimits, { once:true });
  } else {
    setHistDateLimits();
  }
})();
<?php
    return dash_minify_js(ob_get_clean());
}

/**
 * Enfileira CSS/JS do dashboard (uma vez).
 */
function dash_enqueue_dashboard_assets() {
    static $done = false;
    if ($done) return;
    $done = true;

    dash_register_assets();

    wp_enqueue_style('dash-colab-dashboard');
    $css = dash_build_dashboard_css();
    if ($css !== '') {
        wp_add_inline_style('dash-colab-dashboard', $css);
    }

    wp_enqueue_script('dash-colab-dashboard');
    $js = dash_build_dashboard_js();
    if ($js !== '') {
        wp_add_inline_script('dash-colab-dashboard', $js);
    }
}

/**
 * Auto-enfileira assets nas páginas que usam o shortcode.
 */
function dash_maybe_enqueue_dashboard_assets() {
    if (!dash_page_has_dashboard_shortcode()) {
        return;
    }
    $scope_id = dash_detect_shortcode_scope_id();
    $scope_base_id = $scope_id ? dash_maybe_get_parent_product_id($scope_id) : 0;
    if (dash_user_can_view_dashboard($scope_id, $scope_base_id)) {
        dash_enqueue_dashboard_assets();
    } else {
        dash_enqueue_login_assets();
    }
}
add_action('wp_enqueue_scripts','dash_register_assets',5);
add_action('wp_enqueue_scripts','dash_maybe_enqueue_dashboard_assets');

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

    $locked_by_id = $filter_id > 0;
    $locked_course_name = '';
    if ($locked_by_id) {
        $locked_course_name = dash_get_product_name($filter_base_id ?: $filter_id);
    }

    if (!dash_user_can_view_dashboard($filter_id, $filter_base_id)) {
        dash_enqueue_login_assets();
        return dash_render_login_prompt($filter_id, $filter_base_id);
    }

    dash_enqueue_dashboard_assets();

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

    $course_select_html = '';
    if ($locked_by_id) {
        $course_select_html = '';
    } else {
        $course_select_html = '<select id="dash-course-filter" class="dash-input dash-select" aria-label="Filtrar por ID do curso">'
                            . $course_options
                            . '</select>';
    }

    $expiredStatuses = ['CANCELLED','CANCELED','EXPIRED'];
    $overdueStatuses = ['OVERDUE'];
    $mapPaid = ['PAYED','PAID','RECEIVED','RECEIVED_IN_CASH','CONFIRMED'];

    $grupos = ['em_dia'=>[],'em_atraso'=>[],'canceladas'=>[]];

    foreach ($subs as $sub) {
        $subscription_pk_id = (int)($sub['id'] ?? 0);
        $subscriptionID = (string)($sub['subscriptionID'] ?? '');
        $pid_for_select = (int)($pid_map[$subscription_pk_id] ?? 0);
        $has_payments_entry = array_key_exists($subscriptionID, $pay_map);
        $payments_raw = array_values($pay_map[$subscriptionID] ?? []);
        $signup_fee_amount = dash_get_subscription_signup_fee($pid_for_select);
        $split_payments = dash_filter_signup_fee_payments($payments_raw, $signup_fee_amount);
        $payments = array_values($split_payments['payments']);
        $signup_fees = array_values($split_payments['fees']);
        $signup_fee_count = count($signup_fees);
        $signup_fee_payment = $signup_fees ? $signup_fees[0] : null; // pega a 1ª taxa (se houver)
        if ($signup_fee_amount > 0 && $signup_fee_count === 0) {
            // Garante exibição do box mesmo que ainda não haja linha de pagamento marcada como taxa
            $signup_fee_count = 1;
        }

        if ($filter_id) {
            $pid_base = dash_maybe_get_parent_product_id($pid_for_select);
            if ((int)$pid_for_select !== (int)$filter_id && (int)$pid_base !== (int)$filter_base_id) continue;
        }

        $stat_raw = strtoupper(trim($sub['status'] ?? ''));
        if ($has_payments_entry && empty($payments) && empty($signup_fees) && $signup_fee_amount <= 0) continue;

        if (in_array($stat_raw,$expiredStatuses,true)) {
            $grupos['canceladas'][] = ['sub'=>$sub,'payments'=>$payments,'product_id'=>$pid_for_select,'signup_meta'=>['signup_fee_amount'=>$signup_fee_amount,'signup_fee_count'=>$signup_fee_count,'signup_fee_payment'=>$signup_fee_payment]];
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
        $grupos[$key][] = ['sub'=>$sub,'payments'=>$payments,'product_id'=>$pid_for_select,'signup_meta'=>['signup_fee_amount'=>$signup_fee_amount,'signup_fee_count'=>$signup_fee_count,'signup_fee_payment'=>$signup_fee_payment]];
    }

    $cnt_dia = count($grupos['em_dia']);
    $cnt_atraso = count($grupos['em_atraso']);
    $cnt_canceladas = count($grupos['canceladas']);
    $cnt_total = $cnt_dia + $cnt_atraso + $cnt_canceladas;

    $pct_total = $cnt_total > 0 ? 100 : 0;
    $pct_dia = $cnt_total > 0 ? round(($cnt_dia / $cnt_total) * 100) : 0;
    $pct_atraso = $cnt_total > 0 ? round(($cnt_atraso / $cnt_total) * 100) : 0;
    $pct_canceladas = $cnt_total > 0 ? round(($cnt_canceladas / $cnt_total) * 100) : 0;

    $requests_html = '';
    $accepted_html = '';
    if (current_user_can('manage_options')) {
        if (!$locked_by_id) { // só no dashboard geral
            $pending = dash_get_pending_requests();
            $ajax = esc_url(admin_url('admin-ajax.php'));
            $action = esc_url(admin_url('admin-post.php'));
            $req_nonce = wp_create_nonce('dash_pending_requests');
            $cards = dash_render_request_cards($pending, $action, wp_nonce_field('dash_decide_access','_dashdec',true,false), true);
            $requests_html .= '<div class="dash-requests" data-dash-reqs="1" data-req-url="'.$ajax.'" data-req-nonce="'.$req_nonce.'" role="status" aria-live="polite"><h3>Solicitações de acesso</h3><div class="dash-requests-list">'.$cards.'</div></div>';

            $whitelist = dash_get_whitelist();
            if (!empty($whitelist)) {
                $action = esc_url(admin_url('admin-post.php'));
                $nonce   = wp_nonce_field('dash_decide_access','_dashdec',true,false);
                $accepted_html .= '<div class="dash-accepted"><details><summary class="dash-user-icon-wrap"><span class="dash-user-icon" aria-label="Usuários liberados"><span>👤</span></span><span>Usuários liberados</span></summary><div class="dash-accepted-list" role="menu">';
                $accepted_html .= '<div class="dash-accepted-header"><h4>Autorizados</h4><input type="search" class="dash-accepted-search" placeholder="Buscar por nome ou e-mail" aria-label="Buscar usuário liberado"><button type="button" class="dash-accepted-close" aria-label="Fechar" onclick="this.closest(\'details\').removeAttribute(\'open\')">×</button></div>';
                $accepted_html .= '<div class="dash-accepted-body" role="menu">';
                foreach ($whitelist as $uid => $entry) {
                    $u = get_userdata((int)$uid);
                    if (!$u) continue;
                    $nm = $u->display_name ?: $u->user_login;
                    $em = $u->user_email ?: '';

                    $has_general = ($entry === true) || (is_array($entry) && !empty($entry['general']));
                    $ids = [];
                    if ($entry !== true && is_array($entry)) {
                        $ids = array_values(array_unique(array_map('intval', isset($entry['ids']) && is_array($entry['ids']) ? $entry['ids'] : [])));
                    }

                    $scopes_html = '';
                    $scope_count = 0;

                    if ($has_general) {
                        $scope_count++;
                        $label = 'Geral (todos os dashboards)';
                        $scopes_html .= '<div class="dash-accepted-scope" data-scope="0"><span class="dash-accepted-scope-name">'.esc_html($label).'</span><form class="dash-accepted-form" method="post" action="'.$action.'">'.$nonce.'<input type="hidden" name="action" value="dash_decide_access"><input type="hidden" name="user_id" value="'.esc_attr((int)$uid).'"><input type="hidden" name="scope_id" value="0"><input type="hidden" name="decision" value="reject"><button type="submit" class="button dash-accepted-revoke">Revogar</button></form></div>';
                    }

                    foreach ($ids as $sid) {
                        $scope_count++;
                        $base = dash_maybe_get_parent_product_id($sid);
                        $sname = dash_get_product_name($base ?: $sid);
                        $label = $sname ?: ('ID '.$sid);
                        $scopes_html .= '<div class="dash-accepted-scope" data-scope="'.esc_attr($sid).'"><span class="dash-accepted-scope-name" title="'.esc_attr($label).'">'.esc_html($label).'</span><form class="dash-accepted-form" method="post" action="'.$action.'">'.$nonce.'<input type="hidden" name="action" value="dash_decide_access"><input type="hidden" name="user_id" value="'.esc_attr((int)$uid).'"><input type="hidden" name="scope_id" value="'.esc_attr($sid).'"><input type="hidden" name="decision" value="reject"><button type="submit" class="button dash-accepted-revoke">Revogar</button></form></div>';
                    }

                    $meta = trim($em) !== '' ? $em : '';
                    $summary_label = $has_general ? 'Acesso geral' : ($scope_count === 1 ? '1 acesso' : $scope_count.' acessos');
                    $subtitle = $meta ? $meta.' · '.$summary_label : $summary_label;

                    $accepted_html .= '<div class="dash-accepted-item" data-name="'.esc_attr($nm).'" data-email="'.esc_attr($em).'"><details class="dash-accepted-accordion"><summary><div class="dash-accepted-name"><strong>'.esc_html($nm).'</strong><small>'.esc_html($subtitle).'</small></div></summary><div class="dash-accepted-scopes">'.$scopes_html.'</div></details></div>';
                }
                $accepted_html .= '</div></div></details></div>';
            }
        }
    }

    $heading  = $requests_html . '<div class="dash-wrap">' . $accepted_html;
    $heading .= '<h2 class="dash-title">Dashboard de assinaturas</h2>';
    if ($locked_by_id) {
        $heading .= '<div class="dash-locked-course">Curso: <strong>'.esc_html($locked_course_name).'</strong></div>';
        $locked_meta = $locked_by_id
          ? '<div id="dash-locked-meta" data-locked="1" data-course-id="'.esc_attr($filter_id).'" data-course-base-id="'.esc_attr($filter_base_id ?: $filter_id).'" data-course-name="'.esc_attr($locked_course_name).'"></div>'
          : '<div id="dash-locked-meta" data-locked="0"></div>';
        $heading .= $locked_meta;

    }
    $heading .= '</div>';

    // ===== RESUMO =====
    $summary = $heading . '<div class="dash-summary" role="region" aria-label="Resumo das assinaturas"><div class="dash-kpi total" role="status" aria-live="polite"><div class="dash-kpi-label">Assinaturas</div><div class="dash-kpi-value"><span class="dash-kpi-perc" id="kpi-total-perc">'.$pct_total.'%</span><small class="dash-kpi-abs" id="kpi-total-abs">'.(int)$cnt_total.' assinaturas</small></div><div class="dash-kpi-bar" aria-hidden="true"><span id="bar-total" style="width:'.$pct_total.'%"></span></div></div><div class="dash-kpi emdia" role="status" aria-live="polite"><div class="dash-kpi-label">Em Dia</div><div class="dash-kpi-value"><span class="dash-kpi-perc" id="kpi-dia-perc">'.$pct_dia.'%</span><small class="dash-kpi-abs" id="kpi-dia-abs">'.(int)$cnt_dia.' de '.(int)$cnt_total.'</small></div><div class="dash-kpi-bar" aria-hidden="true"><span id="bar-dia" style="width:'.$pct_dia.'%"></span></div></div><div class="dash-kpi atraso" role="status" aria-live="polite"><div class="dash-kpi-label">Em Atraso</div><div class="dash-kpi-value"><span class="dash-kpi-perc" id="kpi-atraso-perc">'.$pct_atraso.'%</span><small class="dash-kpi-abs" id="kpi-atraso-abs">'.(int)$cnt_atraso.' de '.(int)$cnt_total.'</small></div><div class="dash-kpi-bar" aria-hidden="true"><span id="bar-atraso" style="width:'.$pct_atraso.'%"></span></div></div><div class="dash-kpi cancel" role="status" aria-live="polite"><div class="dash-kpi-label">Canceladas</div><div class="dash-kpi-value"><span class="dash-kpi-perc" id="kpi-cancel-perc">'.$pct_canceladas.'%</span><small class="dash-kpi-abs" id="kpi-cancel-abs">'.(int)$cnt_canceladas.' de '.(int)$cnt_total.'</small></div><div class="dash-kpi-bar" aria-hidden="true"><span id="bar-cancel" style="width:'.$pct_canceladas.'%"></span></div></div></div><div class="dash-composition" aria-label="Composição por status (visível)"><div class="dash-stacked"><span id="seg-dia" class="seg seg-dia" style="width:'.$pct_dia.'%"></span><span id="seg-atraso" class="seg seg-atraso" style="width:'.$pct_atraso.'%"></span><span id="seg-cancel" class="seg seg-cancel" style="width:'.$pct_canceladas.'%"></span></div><div class="dash-comp-label">Composição do conjunto visível (Em Dia / Em Atraso / Canceladas)</div></div><div class="dash-summary-note" id="dash-summary-note" data-total="'.(int)$cnt_total.'">Mostrando '.(int)$cnt_total.' de '.(int)$cnt_total.' assinaturas</div>';

    // ===== Barra de busca + Histórico =====
    $search = '<div class="dash-wrap">'.$summary.'
    <!-- 1ª linha: filtros normais -->
    <div class="dash-search" role="search" aria-label="Filtrar assinantes">
      '.$course_select_html.'
      <input type="month" id="dash-month-picker" class="dash-input dash-select" aria-label="Selecionar mês e ano">
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
    $legend = '<div class="dash-legend">
      <span><span class="status-box paid" tabindex="0" aria-label="Pago"></span> Pago</span>
      <span><span class="status-box overdue" tabindex="0" aria-label="Em atraso"></span> Em atraso</span>
      <span><span class="status-box pending" tabindex="0" aria-label="Pendente"></span> Pendente</span>
      <span><span class="status-box future" tabindex="0" aria-label="Não criada"></span> Não criada</span>
    </div>';

    // Inicia HTML
    $html  = $search . $legend;

    // ===== Tabela builder =====
    $build_row = function(array $sub, array $payments, int $product_id, array $meta = []) use ($agora, $hojeYmd) {
        $subscription_pk_id = (int)($sub['id'] ?? 0);
        $base_id = dash_maybe_get_parent_product_id($product_id);
        $signup_fee_count = (int)($meta['signup_fee_count'] ?? 0);
        $signup_fee_amount = (float)($meta['signup_fee_amount'] ?? 0);
        if ($signup_fee_amount > 0 && $signup_fee_count === 0) {
            $signup_fee_count = 1;
        }
        $signup_fee_payment = $meta['signup_fee_payment'] ?? null;
        $signup_fee_attr = $signup_fee_amount > 0 ? number_format($signup_fee_amount, 2, '.', '') : '0.00';

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
        $r  = '<tr id="sub-'.(int)$subscription_pk_id.'" data-course-id="'.esc_attr($product_id ?: 0).'" data-course-base-id="'.esc_attr($base_id ?: 0).'" data-signup-fee-count="'.esc_attr($signup_fee_count).'" data-signup-fee="'.esc_attr($signup_fee_attr).'">';
        $r .= '<td>'.esc_html($sub['customer_name'] ?? '—').'</td>';
        $r .= '<td>'.esc_html($sub['customer_email'] ?? '—').'</td>';
        $r .= '<td>'.esc_html($cpf_display).'</td>';

        $r .= '<td>';

if ($signup_fee_amount > 0 || $signup_fee_count > 0) {
    $fee_val = $signup_fee_amount > 0 ? number_format($signup_fee_amount, 2, ',', '.') : '—';

    // Defaults para taxa sem linha registrada (override)
    $cls_fee = 'pending';
    $status_label_fee = 'Pendente';
    $status_tip_fee = 'Taxa de inscrição — '.$status_label_fee;
    $tooltip_fee = "Status: {$status_tip_fee}\nValor: R$ {$fee_val}\nCriação: —\nVencimento: —\nPagamento: —";
    $created_label_fee = $venc_label_fee = $pag_label_fee = '—';
    $due_ts_fee = 0;
    $created_ymd_fee = $due_ymd_fee = $paid_ymd_fee = '';
    $pay_raw_fee = '';

    if (is_array($signup_fee_payment) && !empty($signup_fee_payment)) {
        $p = $signup_fee_payment;
        $val_raw = isset($p['value']) ? number_format((float)$p['value'], 2, ',', '.') : $fee_val;
        $fee_val = $val_raw;

        $cre_raw = $p['created'] ?? ($p['createdAt'] ?? '');
        if ($cre_raw) {
            $created_ymd_fee = date_i18n('Y-m-d', strtotime($cre_raw));
            $created_label_fee = date_i18n('d/m/Y - H:i', strtotime($cre_raw));
        }

        $due_ts_fee  = strtotime($p['dueDate'] ?? '');
        $orig_ts_fee = strtotime($p['originalDueDate'] ?? '');
        $due_ts_fee  = $due_ts_fee ?: $orig_ts_fee;
        if ($due_ts_fee) {
            $due_ymd_fee = date_i18n('Y-m-d', $due_ts_fee);
            $venc_label_fee = date_i18n('d/m/Y', $due_ts_fee);
        }

        $pay_raw_fee = $p['paymentDate'] ?? '';
        if ($pay_raw_fee) {
            $paid_ymd_fee = date_i18n('Y-m-d', strtotime($pay_raw_fee));
            $pag_label_fee = date_i18n('d/m/Y', strtotime($pay_raw_fee));
        }

        $p_stat_fee   = strtoupper($p['paymentStatus'] ?? '');
        $isPaidFee    = in_array($p_stat_fee, ['PAYED','PAID','RECEIVED','RECEIVED_IN_CASH','CONFIRMED'], true);
        $isOverFee    = (!$isPaidFee && ($p_stat_fee === 'OVERDUE' || ($due_ymd_fee && $due_ymd_fee < $hojeYmd)));

        if ($isPaidFee)       { $cls_fee='paid';    $status_label_fee='Pago'; }
        elseif ($isOverFee)   { $cls_fee='overdue'; $status_label_fee='Em atraso'; }
        else                  { $cls_fee='pending'; $status_label_fee='Pendente'; }

        $status_tip_fee = 'Taxa de inscrição — '.$status_label_fee;
        $tooltip_fee = "Status: {$status_tip_fee}\nValor: R$ {$fee_val}\nCriação: {$created_label_fee}\nVencimento: {$venc_label_fee}\nPagamento: {$pag_label_fee}";
    }

    $r .= '<span class="status-box signup-fee '.esc_attr($cls_fee).'"'
        .' data-tooltip="'.esc_attr($tooltip_fee).'"'
        .' tabindex="0" aria-label="'.esc_attr($status_tip_fee).'"'
        .' data-date="'.esc_attr($due_ymd_fee).'" data-month="'.esc_attr($due_ts_fee ? date_i18n('Y-m', $due_ts_fee) : '').'" data-due-ymd="'.esc_attr($due_ymd_fee).'" data-created-ymd="'.esc_attr($created_ymd_fee).'" data-paid-ymd="'.esc_attr($paid_ymd_fee).'"'
        .' data-de-criacao="'.esc_attr($created_label_fee !== '—' ? $created_label_fee : '').'" data-de-vencimento="'.esc_attr($due_ts_fee ? date_i18n('d/m/Y', $due_ts_fee) : '').'" data-de-pagamento="'.esc_attr($pay_raw_fee ? date_i18n('d/m/Y', strtotime($pay_raw_fee)) : '').'"'
        .' data-signup-fee="'.esc_attr($fee_val).'"'
        .'></span>';
    $r .= '<span class="status-separator" aria-hidden="true">-</span>';
}

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

    $html = $search . $legend;

    foreach (['em_atraso'=>'Em Atraso','em_dia'=>'Em Dia','canceladas'=>'Canceladas'] as $key=>$titulo) {
        $html .= '<h3 class="dash-h3">Assinaturas — '.esc_html($titulo).'</h3>';
        $html .= '<div class="dash-table-scroll" role="region" aria-label="Tabela '.esc_attr($titulo).'">';
        $html .= "<table id='dash-table-{$key}' class='dash-table' role='table' aria-label='Assinaturas {$titulo}'><thead><tr><th>Nome</th><th>E-mail</th><th>CPF</th><th>Pagamentos</th><th>Status</th></tr></thead><tbody>";
        if (empty($grupos[$key])) {
            $html .= "<tr><td colspan='5'><em>Nenhuma assinatura nesta categoria.</em></td></tr>";
        } else {
            foreach ($grupos[$key] as $entry) { $html .= $build_row($entry['sub'],$entry['payments'],(int)$entry['product_id'],$entry['signup_meta'] ?? []); }
        }
        $html .= '</tbody></table></div>';
    }

    

    return $html;
}
add_shortcode('dashboard_assinantes_e_pedidos','mostrar_dashboard_assinantes');
