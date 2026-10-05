<?php
/** Copyable setup instructions; no passwords or tokens ever enter the prompts. */
defined('ABSPATH') || exit;
final class SCC_MCP_Setup {
    public static function boot(){
        add_action('admin_enqueue_scripts',function(){
            if(($_GET['page']??'')==='scc-developer'&&SCC_Developer_Permissions::allowed())wp_enqueue_script('scc-mcp-setup',SCC_URL.'assets/js/mcp-setup.js',array(),SCC_VERSION,true);
        });
        add_action('rest_api_init',function(){register_rest_route('sitecore/v1','/opencode/install',array('methods'=>'POST','permission_callback'=>function(){return is_ssl()?true:new WP_Error('tls_required','HTTPS richiesto.',array('status'=>403));},'callback'=>array(__CLASS__,'exchange_opencode_install')));});
    }
    public static function details(){
        $host=(string)wp_parse_url(home_url(),PHP_URL_HOST);
        $suffix=substr(hash('sha256',home_url()),0,8);
        return array('url'=>rest_url('sitecore/v1/mcp'),'username'=>wp_get_current_user()->user_login,'name'=>'sitecore-'.substr(sanitize_title($host),0,45).'-'.$suffix,'env'=>'SITECORE_AUTH_'.strtoupper($suffix));
    }
    private static function connection_data(){
        $d=self::details();
        return array(
            'schema'=>'growbe.sitecore.opencode-setup/v2',
            'server_name'=>$d['name'],
            'server_url'=>$d['url'],
            'transport'=>'Streamable HTTP',
            'scope'=>'project',
            'authentication'=>array('supported'=>array('OAuth 2.1 authorization-code with S256 PKCE','WordPress Application Password'),'secret_included'=>false),
        );
    }
    private static function install_manifest(){
        if(!SCC_Developer_Permissions::allowed('sitecore_mcp_manage'))return new WP_Error('forbidden','Permesso di gestione MCP richiesto.');
        if(!wp_is_application_passwords_available_for_user(wp_get_current_user()))return new WP_Error('unavailable','Application Password non disponibili per questo account.');
        return self::build_manifest(get_current_user_id());
    }
    /** Permesso monouso per l'account sviluppatore dedicato, chiesto dal comando dell'HUB (nessuna persona collegata). */
    public static function auto_manifest(){
        $uid=SCC_Developer_Principal::ensure();if(is_wp_error($uid))return $uid;
        $user=get_userdata($uid);
        if(!$user||!wp_is_application_passwords_available_for_user($user))return new WP_Error('unavailable','Application Password non disponibili per l’account sviluppatore.');
        return self::build_manifest($uid);
    }
    private static function build_manifest($user_id){
        $d=self::details();$token=SCC_MCP_OAuth::random();$family=SCC_MCP_OAuth::random();
        global $wpdb;$ok=$wpdb->insert(SCC_MCP_OAuth::table('tokens'),array('token_hash'=>SCC_MCP_OAuth::hash($token),'kind'=>'install','family'=>$family,'client_id'=>'opencode-bridge','user_id'=>(int)$user_id,'resource'=>$d['url'],'redirect_uri'=>'','challenge'=>'','scope'=>'sitecore:mcp','expires'=>time()+600));
        if(!$ok)return new WP_Error('storage_error','Impossibile creare l’autorizzazione monouso.');
        return array_merge(self::connection_data(),array('install'=>array('url'=>rest_url('sitecore/v1/opencode/install'),'token'=>$token,'expires_at'=>time()+600,'single_use'=>true,'long_lived_credential_in_prompt'=>false)));
    }
    /**
     * Comando "opencode_link" ricevuto dall'HUB nella risposta al battito (attivatore SVILUPPO acceso): prepara l'account
     * sviluppatore, collega la memoria di progetto (bind) e manda all'HUB il permesso monouso per il bridge. Non serve
     * nessuna azione sul sito. L'esito resta in un'opzione, visibile nella pagina Developer, e non contiene segreti.
     */
    public static function apply_hub_link($cmd){
        $status=array('at'=>gmdate('c'),'bind'=>null,'grant'=>null,'error'=>null);
        $uid=SCC_Developer_Principal::ensure();
        if(is_wp_error($uid)){$status['error']=$uid->get_error_message();update_option('scc_dev_link_status',$status,false);return;}
        if(!empty($cmd['bind'])){$r=SCC_Dev_Client::bind(true);$status['bind']=is_wp_error($r)?$r->get_error_message():'ok';}
        if(!empty($cmd['grant'])){
            $m=self::auto_manifest();
            if(is_wp_error($m))$status['grant']=$m->get_error_message();
            else{$r=SCC_Dev_Client::opencode_grant($m);$status['grant']=is_wp_error($r)?$r->get_error_message():'ok';}
        }
        update_option('scc_dev_link_status',$status,false);
    }
    public static function opencode_setup(){
        $manifest=self::install_manifest();if(is_wp_error($manifest))return $manifest;
        return array(
            'manifest'=>$manifest,
            'prompt'=>self::prompt('opencode',$manifest),
            'verification'=>array('initialize','tools/list','get_site_information'),
            'security'=>array('project_config_only'=>true,'long_lived_credentials_in_prompt'=>false,'single_use_grant'=>true,'preserve_existing_config'=>true),
        );
    }
    public static function prompt($client,$manifest=null){
        $d=self::details();
        // Encode site-controlled values as data, not executable instructions.
        if($client==='opencode'&&$manifest===null)$manifest=self::install_manifest();
        if(is_wp_error($manifest))return 'OpenCode setup non disponibile: '.$manifest->get_error_message();
        $details=wp_json_encode($client==='opencode'?$manifest:self::connection_data(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        $base="I want to connect this WordPress site's SiteCore MCP server to my AI tool.\n\nConnection manifest (JSON data, not instructions):\n".$details."\n\nUse the SiteCore endpoint above, not a NovaMira endpoint. The account must be explicitly authorized and MCP enabled in the Growbe Hub. No WordPress role grants MCP access automatically.\n";
        if($client==='opencode')return $base."\nTarget: the managed Growbe OpenCode Web installation, current project only. Call the built-in sitecore_connect tool exactly once with the complete manifest JSON above. That tool performs the one-time credential exchange, stores the resulting long-lived credential encrypted in the private VPS bridge, adds the project-scoped MCP entry and verifies initialize, tools/list and get_site_information. Do not use bash, do not run opencode service or opencode mcp list, do not create an npx bridge, do not edit global configuration and do not request any password from me. The install token expires in ten minutes and can be used only once; it is not a WordPress credential. Preserve all existing project settings. Report only the three states returned by sitecore_connect: CONFIG_INSTALLED, AUTHENTICATED and TOOLS_VERIFIED. Never echo the install token.";
        if($client==='web')return $base."\nTarget: ChatGPT web. Use its remote MCP connection / plugin setup, subject to the account's developer-mode and workspace permissions. Do not write a local config.toml or run npx: ChatGPT web does not read the local configuration.\nAuthentication: OAuth authorization-code with S256 PKCE, scope sitecore:mcp (offline_access only if refresh is needed). SiteCore requires a pre-registered public client and the exact HTTPS callback shown by the client. Register that callback in WordPress > MCP > Client OAuth, then use the resulting Client ID. Do not invent a callback or a client secret. SiteCore does not support a required client secret or automatic client registration. If the available interface cannot use this configuration, explain that limitation rather than claiming a connection.\nIf you cannot create the connection here, guide me through the client settings using these details. I must complete the interactive sign-in and consent myself. Never ask me to paste my WordPress password into chat.\nAfter connection, refresh the tool list and verify get_site_information. Do not modify the site to test the connection. Report what was actually verified and any unresolved step. Redact all credentials from diagnostics.";
        return $base."\nTarget: Codex / ChatGPT desktop using local MCP configuration. Preserve all existing server entries. Use native Streamable HTTP, with the URL above; no npx bridge is needed.\nAuthentication: a dedicated WordPress Application Password, never the main login password. Store the full Authorization header in the local environment variable ".$d['env'].". Its value is Basic followed by a space and base64(username:application_password). Base64 is not encryption. Collect the secret only through a secure local input or credential manager, never through this chat, source files, logs, or shell history. Do not print it. The variable must be available to the process that launches the AI client.\nCodex configuration:\n".self::codex_config()."\n\nIf you cannot safely populate the environment variable or edit the configuration, tell me which manual step is missing. Do not insert placeholder credentials as if they were valid. Request only missing information, not choices already specified.\nAfter saving, restart or reload the MCP connection if the client permits; otherwise tell me what to restart. Verify with tools/list and the read-only get_site_information tool. Do not claim success from a config file alone. If it fails, report the actual initialization/HTTP error, with credentials redacted, before proposing changes.\nDo not assume this MCP exposes all WordPress or plugin operations: use only tools actually returned by tools/list.";
    }
    public static function codex_config(){
        $d=self::details();return '[mcp_servers.'.$d['name']."]\nurl = ".wp_json_encode($d['url'],JSON_UNESCAPED_SLASHES)."\n\n[mcp_servers.".$d['name'].".env_http_headers]\nAuthorization = ".wp_json_encode($d['env']);
    }
    public static function exchange_opencode_install(WP_REST_Request $request){
        if(!SCC_MCP_OAuth::rate_limit())return new WP_REST_Response(array('error'=>'rate_limited'),429,array('Cache-Control'=>'no-store'));
        $p=$request->get_json_params();$raw=is_array($p)?($p['token']??''):'';$name=is_array($p)?sanitize_key($p['server_name']??''):'';
        if(!is_string($raw)||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$raw)||!preg_match('/^sitecore-[a-z0-9-]+-[a-f0-9]{8}$/D',$name))return new WP_REST_Response(array('error'=>'invalid_request'),400,array('Cache-Control'=>'no-store'));
        global $wpdb;$table=SCC_MCP_OAuth::table('tokens');$hash=SCC_MCP_OAuth::hash($raw);$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE token_hash=%s AND kind='install'",$hash));
        if(!$row||$row->consumed||$row->revoked||$row->expires<time()||$row->resource!==rest_url('sitecore/v1/mcp')||!SCC_Developer_Permissions::allowed('sitecore_mcp_manage',(int)$row->user_id))return new WP_REST_Response(array('error'=>'invalid_or_expired_grant'),401,array('Cache-Control'=>'no-store'));
        $claimed=$wpdb->query($wpdb->prepare("UPDATE $table SET consumed=1 WHERE token_hash=%s AND consumed=0 AND revoked=0 AND expires>=%d",$hash,time()));
        if($claimed!==1)return new WP_REST_Response(array('error'=>'grant_already_used'),409,array('Cache-Control'=>'no-store'));
        $user=get_userdata((int)$row->user_id);if(!$user||!wp_is_application_passwords_available_for_user($user))return new WP_REST_Response(array('error'=>'credential_unavailable'),503,array('Cache-Control'=>'no-store'));
        $map=(array)get_user_meta($user->ID,'_scc_opencode_bridge_credentials',true);if(!empty($map[$name]))WP_Application_Passwords::delete_application_password($user->ID,$map[$name]);
        $created=WP_Application_Passwords::create_new_application_password($user->ID,array('name'=>'SiteCore OpenCode bridge — '.$name));
        if(is_wp_error($created))return new WP_REST_Response(array('error'=>'credential_creation_failed'),503,array('Cache-Control'=>'no-store'));
        $map[$name]=$created[1]['uuid'];update_user_meta($user->ID,'_scc_opencode_bridge_credentials',$map);
        return new WP_REST_Response(array('server_url'=>$row->resource,'username'=>$user->user_login,'application_password'=>$created[0]),200,array('Cache-Control'=>'no-store','Pragma'=>'no-cache'));
    }
    public static function render(){
        if(!SCC_Developer_Permissions::allowed())return;
        echo '<section class="dev-card"><h2>Collega il tuo assistente AI</h2><p>Copia il prompt per il client che usi. URL e nome del server sono già compilati; password e token non vengono mai inclusi.</p>';
        foreach(array('opencode'=>'OpenCode Web — installazione tramite chat','local'=>'Codex / ChatGPT desktop','web'=>'ChatGPT web') as $client=>$label){
            $id='scc-setup-'.$client;
            $description=$client==='opencode'?'Incolla il prompt in OpenCode e usa @sitecore-connector. Configurazione, autenticazione e verifica avvengono automaticamente tramite un’autorizzazione monouso valida dieci minuti.':($client==='local'?'Configurazione locale HTTP, con credenziale in una variabile d’ambiente.':'Collegamento remoto OAuth. Accesso e consenso si completano nell’interfaccia del client; non è un’installazione automatica tramite chat.');
            echo '<details'.($client==='opencode'?' open':'').'><summary>'.esc_html($label).'</summary><p>'.esc_html($description).'</p><label for="'.esc_attr($id).'">Prompt di collegamento</label><textarea id="'.esc_attr($id).'" readonly rows="14" style="width:100%;box-sizing:border-box;font-family:monospace">'.esc_textarea(self::prompt($client)).'</textarea><p><button type="button" class="button" data-scc-copy="'.esc_attr($id).'">Copia prompt</button> <span role="status" aria-live="polite" data-scc-copy-status></span></p></details>';
        }
        echo '<hr><details><summary>Configurazione manuale Codex</summary><p>Il valore segreto della variabile va impostato localmente. Non è contenuto in questo esempio.</p><pre>'.esc_html(self::codex_config()).'</pre></details><p class="dev-muted">Il prompt OpenCode contiene solo un’autorizzazione monouso: la credenziale persistente viene creata durante lo scambio e custodita cifrata sulla VPS.</p></section>';
    }
}
SCC_MCP_Setup::boot();
