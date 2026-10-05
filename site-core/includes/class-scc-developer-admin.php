<?php
defined('ABSPATH') || exit;
final class SCC_Developer_Admin {
    private static $notice='';
    private static $secret='';
    public static function boot(){
        add_filter('woocommerce_prevent_admin_access',function($prevent){return ($_GET['page']??'')==='scc-developer'&&SCC_Developer_Permissions::allowed()?false:$prevent;});
        add_action('admin_menu',function(){
            // Keep the existing parent restricted; authorized non-admin accounts
            // receive a Site Core entry pointing only to their MCP screen.
            if(!current_user_can('manage_options')&&SCC_Developer_Permissions::allowed())add_menu_page('Site Core','Site Core','read','scc-developer',array(__CLASS__,'render'),'dashicons-admin-generic',81);
            else add_submenu_page('scc-entries','SiteCore MCP','MCP','read','scc-developer',array(__CLASS__,'render'));
        },20);
        add_action('admin_init',array(__CLASS__,'actions'));
    }
    public static function actions(){
        if(($_GET['page']??'')!=='scc-developer')return;
        if($_SERVER['REQUEST_METHOD']==='POST'&&!SCC_Developer_Permissions::allowed())wp_die('Account non autorizzato dall’HUB.',403);
        nocache_headers();header('Referrer-Policy: no-referrer');
        if($_SERVER['REQUEST_METHOD']!=='POST')return;
        check_admin_referer('scc_developer');
        if(!SCC_Developer_Permissions::allowed('sitecore_mcp_manage'))wp_die('Permesso di gestione richiesto.',403);
        $action=sanitize_key($_POST['developer_action']??'');$out=true;
        if($action==='bind'){$out=SCC_Dev_Client::bind();if(!is_wp_error($out))SCC_Dev_Client::health();}
        elseif($action==='create_password'){
            if(!wp_is_application_passwords_available_for_user(wp_get_current_user()))$out=new WP_Error('unavailable','Application Password non disponibili per questo utente.');
            else {$out=WP_Application_Passwords::create_new_application_password(get_current_user_id(),array('name'=>'SiteCore MCP — '.sanitize_text_field(wp_unslash($_POST['credential_name']??'Client'))));if(!is_wp_error($out))self::$secret=$out[0];}
        }elseif($action==='revoke_password'){$out=WP_Application_Passwords::delete_application_password(get_current_user_id(),sanitize_text_field(wp_unslash($_POST['uuid']??'')));}
        elseif($action==='register_client'){$out=SCC_MCP_OAuth::register_client(sanitize_text_field(wp_unslash($_POST['client_name']??'')),array(trim(wp_unslash($_POST['redirect_uri']??''))));}
        elseif($action==='revoke_client'){
            global $wpdb;$id=sanitize_text_field(wp_unslash($_POST['client_id']??''));
            $wpdb->update(SCC_MCP_OAuth::table('clients'),array('revoked'=>1),array('client_id'=>$id));
            $wpdb->update(SCC_MCP_OAuth::table('tokens'),array('revoked'=>1),array('client_id'=>$id));
        }else $out=new WP_Error('invalid_action','Azione non riconosciuta.');
        self::$notice=is_wp_error($out)?$out->get_error_message():'Operazione completata.';
        if(!self::$secret&&!is_wp_error($out)){wp_safe_redirect(add_query_arg(array('page'=>'scc-developer','updated'=>'1'),admin_url('admin.php')));exit;}
    }
    private static function sync_form(){
        if(!current_user_can('manage_options'))return;
        if(isset($_GET['synced']))echo '<p role="status">'.($_GET['synced']==='1'?'Elenco account sincronizzato. Ora scegli gli accessi nell’HUB.':'Sincronizzazione non riuscita. Verifica il collegamento e riprova.').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="scc_mcp_sync">';wp_nonce_field('scc_mcp_sync');echo '<button class="button">Sincronizza account con l’HUB</button></form>';
    }
    private static function start($action){echo '<form method="post">';wp_nonce_field('scc_developer');echo '<input type="hidden" name="developer_action" value="'.esc_attr($action).'">';}
    private static function value($value){return esc_html(is_scalar($value)?(string)$value:wp_json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    public static function render(){
        if(!current_user_can('read'))wp_die('Accesso negato.',403);
        if(!SCC_Developer_Permissions::allowed()){
            echo '<div class="wrap"><h1>SiteCore MCP</h1><p>Accesso non abilitato per questo account, MCP disattivato o HUB non raggiungibile. I permessi si gestiscono esclusivamente in GestioneSiti: scheda sito → Developer → MCP.</p>';
            self::sync_form();echo '</div>';return;
        }
        $tab=($_GET['tab']??'')==='context'?'context':'mcp';$url=admin_url('admin.php?page=scc-developer');$manage=SCC_Developer_Permissions::allowed('sitecore_mcp_manage');
        echo '<div class="wrap scc-dev"><style>.scc-dev{max-width:1100px;color:#111}.scc-dev .dev-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin:20px 0}.scc-dev .dev-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:20px}.scc-dev code,.scc-dev pre{overflow-wrap:anywhere;white-space:pre-wrap}.scc-dev .dev-muted{color:#6b7280}.scc-dev input[type=text],.scc-dev input[type=url]{width:100%;max-width:560px;margin:6px 0 14px;border-radius:8px}.scc-dev th{text-align:left}.scc-dev td,.scc-dev th{padding:12px 8px;border-bottom:1px solid #e5e7eb}.scc-dev table{width:100%;border-collapse:collapse}.scc-dev .button{border-radius:8px}.scc-dev summary{cursor:pointer;font-weight:600}</style>';
        echo '<h1>SiteCore MCP</h1><p class="dev-muted">Un solo server MCP. Memoria tecnica e skill centralizzate in GestioneSiti.</p><nav class="nav-tab-wrapper"><a class="nav-tab '.($tab==='mcp'?'nav-tab-active':'').'" href="'.esc_url($url).'">MCP</a><a class="nav-tab '.($tab==='context'?'nav-tab-active':'').'" href="'.esc_url($url.'&tab=context').'">Project Context</a></nav>';
        if(self::$notice||isset($_GET['updated']))echo '<div class="dev-card" role="status">'.esc_html(self::$notice?:'Operazione completata.').'</div>';
        if(self::$secret)echo '<div class="dev-card"><h2>Salva ora la password</h2><p>Questa è l’unica visualizzazione. Conservala nel gestore credenziali del client: non nella memoria di progetto.</p><code>'.esc_html(self::$secret).'</code></div>';
        if($tab==='context'){
            if(!SCC_Developer_Permissions::allowed('sitecore_dev_context_read')){echo '<p>Permesso di lettura richiesto.</p></div>';return;}
            $data=SCC_Dev_Client::request('context');
            echo '<section class="dev-card"><h2>Contesto del progetto</h2><p class="dev-muted">Lettura dalla fonte centrale. Nessuna copia permanente su questo sito.</p>';
            if(is_wp_error($data))echo '<p role="alert">'.esc_html($data->get_error_message()).'</p>';
            else {echo '<p>Revisione '.(int)$data['revision'].'</p>';foreach((array)$data['context'] as $k=>$v)echo '<h3>'.esc_html(str_replace('_',' ',$k)).'</h3><pre>'.self::value($v).'</pre>';if(empty($data['context']))echo '<p>Il contesto non è ancora stato compilato.</p>';}
            echo '</section></div>';return;
        }
        SCC_MCP_Setup::render();
        $available=SCC_MCP_Tools::listing();
        echo '<section class="dev-card"><h2>Strumenti disponibili <span class="dev-muted">('.count($available).')</span></h2><p>Il catalogo dipende dai plugin attivi e dai permessi WordPress del tuo account. Le scritture richiedono conferma e un identificatore univoco; WPML non è incluso.</p><details><summary>Consulta abilities e parametri</summary>';
        foreach($available as $tool)echo '<details style="margin:12px 0"><summary>'.esc_html($tool['name']).' · '.($tool['annotations']['readOnlyHint']?'Lettura':'Modifica').'</summary><p>'.esc_html($tool['description']).'</p><pre>'.self::value($tool['inputSchema']).'</pre></details>';
        echo '</details></section>';
        $enabled=SCC_Developer_Permissions::allowed();$binding=get_option('scc_dev_binding',array());$endpoint=rest_url('sitecore/v1/mcp');
        echo '<div class="dev-grid"><section class="dev-card"><h2>Server MCP · '.($enabled?'Attivo':'Disattivato').'</h2><p><strong>Sito</strong><br>'.esc_html(home_url()).'</p><p><strong>Endpoint unico</strong><br><code>'.esc_html($endpoint).'</code></p><p>Streamable HTTP · JSON stateless<br>Application Password oppure OAuth 2.1 / PKCE</p><p>Account autorizzato: '.esc_html(wp_get_current_user()->user_login).'</p><p>Ultima autenticazione: '.esc_html(get_option('scc_mcp_last_authenticated')?:'Non ancora rilevata').'</p>';
        echo '<p>Attivazione e account autorizzati sono gestiti esclusivamente dall’HUB.</p>';self::sync_form();
        echo '</section><section class="dev-card"><h2>Collegamento a GestioneSiti</h2><p>SiteCore '.esc_html(SCC_VERSION).' installato.</p><p>'.(!empty($binding['site_id'])?'Progetto #'.(int)$binding['site_id']:'Non ancora collegato').'</p><p>Il collegamento usa una credenziale interna separata dalle password MCP.</p>';
        if($manage){self::start('bind');echo '<button class="button">Collega / riassocia il sito</button></form>';}
        echo '<p><a class="button" href="'.esc_url(wp_nonce_url($url.'&health=1','scc_health')).'">Verifica connessione</a></p>';
        if(isset($_GET['health'])&&wp_verify_nonce($_GET['_wpnonce']??'','scc_health'))echo '<pre>'.self::value(SCC_Dev_Client::health()).'</pre>';
        echo '<p class="dev-muted">Prima abilita l’associazione nella scheda Developer del sito su GestioneSiti.</p></section></div>';
        if($manage){
            echo '<section class="dev-card"><h2>Application Password</h2><p>Credenziali del tuo utente. Revocabili singolarmente; il valore completo non viene conservato in chiaro.</p>';
            self::start('create_password');echo '<label>Nome del client<br><input type="text" name="credential_name" required maxlength="80" placeholder="Es. workstation sviluppo"></label><br><button class="button">Genera una nuova password</button></form>';
            echo '<table><thead><tr><th>Nome</th><th>Creata</th><th>Ultimo utilizzo</th><th>Azione</th></tr></thead><tbody>';
            foreach(WP_Application_Passwords::get_user_application_passwords(get_current_user_id()) as $credential){echo '<tr><td>'.esc_html($credential['name']).'</td><td>'.esc_html(gmdate('Y-m-d',$credential['created'])).'</td><td>'.esc_html(!empty($credential['last_used'])?gmdate('Y-m-d',$credential['last_used']):'Mai').'</td><td>';self::start('revoke_password');echo '<input type="hidden" name="uuid" value="'.esc_attr($credential['uuid']).'"><button class="button">Revoca</button></form></td></tr>';}
            echo '</tbody></table></section><section class="dev-card"><h2>Client OAuth</h2><p>Registra il callback HTTPS esatto mostrato dal client. Nessuna registrazione pubblica automatica.</p>';
            self::start('register_client');echo '<label>Nome<br><input type="text" name="client_name" maxlength="120" required></label><br><label>Callback URL<br><input type="url" name="redirect_uri" required placeholder="https://…"></label><br><button class="button">Registra client</button></form>';
            global $wpdb;$clients=$wpdb->get_results('SELECT client_id,name,created_at FROM '.SCC_MCP_OAuth::table('clients').' WHERE revoked=0 ORDER BY created_at DESC');
            foreach($clients as $client){echo '<hr><h3>'.esc_html($client->name).'</h3><p>Client ID: <code>'.esc_html($client->client_id).'</code></p><p>Client pubblico con PKCE: nessun client secret.</p>';self::start('revoke_client');echo '<input type="hidden" name="client_id" value="'.esc_attr($client->client_id).'"><button class="button">Revoca client e sessioni</button></form>';}
            echo '</section>';
        }
        $config=array('mcpServers'=>array('sitecore'=>array('type'=>'http','url'=>$endpoint,'headers'=>array('Authorization'=>'Basic <BASE64_USERNAME:APPLICATION_PASSWORD>'))));
        echo '<section class="dev-card"><h2>Configurazione client</h2><details><summary>Claude Code — connessione HTTP</summary><p>Sostituisci il segnaposto nel tuo gestore locale dei segreti. Non salvare credenziali nel repository.</p><pre>'.self::value($config).'</pre></details><hr><details><summary>ChatGPT — connessione OAuth</summary><p>Usa lo stesso endpoint MCP qui sopra e il Client ID registrato. Imposta OAuth con client pubblico / PKCE e autorizza con il tuo account WordPress autorizzato. Se il client richiede obbligatoriamente un client secret, questa modalità non è supportata.</p><p>Issuer: <code>'.esc_html(SCC_MCP_OAuth::issuer()).'</code></p></details></section></div>';
    }
}
SCC_Developer_Admin::boot();
