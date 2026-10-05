<?php
/** Authorization-code + S256 PKCE. Pre-registered clients; no password grant. */
defined('ABSPATH') || exit;
final class SCC_MCP_OAuth {
    public static function table($suffix){global $wpdb;return $wpdb->prefix.'scc_oauth_'.$suffix;}
    public static function endpoint(){return rest_url('sitecore/v1/mcp');}
    public static function issuer(){return rest_url('sitecore/v1/oauth');}
    public static function random(){return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');}
    public static function hash($s){return hash('sha256',$s);}
    public static function error($code='invalid_grant',$status=400){return new WP_REST_Response(array('error'=>$code),$status,array('Cache-Control'=>'no-store','Pragma'=>'no-cache'));}
    public static function boot(){
        add_action('init',array(__CLASS__,'install'),3);
        add_action('init',array(__CLASS__,'metadata_discovery'),4);
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('admin_post_scc_oauth_authorize',array(__CLASS__,'authorize'));
        add_action('admin_post_nopriv_scc_oauth_authorize',array(__CLASS__,'authorize'));
        add_action('scc_oauth_cleanup',array(__CLASS__,'cleanup'));
    }
    public static function install(){
        if(!wp_next_scheduled('scc_oauth_cleanup'))wp_schedule_event(time()+3600,'daily','scc_oauth_cleanup');
        if(get_option('scc_oauth_schema')==='2')return;
        global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$c=$wpdb->get_charset_collate();$clients=self::table('clients');$tokens=self::table('tokens');
        dbDelta("CREATE TABLE $clients (client_id varchar(64) NOT NULL, name varchar(120) NOT NULL, redirects longtext NOT NULL, created_at datetime NOT NULL, revoked tinyint NOT NULL DEFAULT 0, PRIMARY KEY  (client_id)) $c;");
        dbDelta("CREATE TABLE $tokens (
token_hash varchar(64) NOT NULL,
kind varchar(12) NOT NULL,
family varchar(64) NOT NULL,
client_id varchar(64) NOT NULL,
user_id bigint unsigned NOT NULL,
resource varchar(500) NOT NULL,
redirect_uri varchar(2000) NOT NULL,
challenge varchar(64) NOT NULL,
scope varchar(100) NOT NULL DEFAULT 'sitecore:mcp',
expires bigint unsigned NOT NULL,
consumed tinyint NOT NULL DEFAULT 0,
revoked tinyint NOT NULL DEFAULT 0,
PRIMARY KEY  (token_hash),
KEY family (family),
KEY user_client (user_id,client_id),
KEY expires (expires)
) $c;");
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$clients))===$clients&&$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$tokens))===$tokens)update_option('scc_oauth_schema','2',false);
    }
    public static function cleanup(){global $wpdb;$wpdb->query($wpdb->prepare('DELETE FROM '.self::table('tokens').' WHERE expires<%d LIMIT 5000',time()-2592000));}
    public static function rate_limit(){
        // Hash remote address; do not trust forwarding headers supplied by clients.
        $key='scc_oauth_rate_'.hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown').'|'.intdiv(time(),60),wp_salt('nonce'));
        $n=(int)get_transient($key);if($n>=30)return false;set_transient($key,$n+1,120);return true;
    }
    public static function metadata(){return array('issuer'=>self::issuer(),'authorization_endpoint'=>admin_url('admin-post.php?action=scc_oauth_authorize'),'token_endpoint'=>rest_url('sitecore/v1/oauth/token'),'revocation_endpoint'=>rest_url('sitecore/v1/oauth/revoke'),'response_types_supported'=>array('code'),'grant_types_supported'=>array('authorization_code','refresh_token'),'token_endpoint_auth_methods_supported'=>array('none'),'code_challenge_methods_supported'=>array('S256'),'scopes_supported'=>array('sitecore:mcp','offline_access'));
    }
    public static function resource_metadata(){return array('resource'=>self::endpoint(),'authorization_servers'=>array(self::issuer()),'scopes_supported'=>array('sitecore:mcp'),'bearer_methods_supported'=>array('header'));}
    public static function metadata_discovery(){
        $uri=wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);$issuer=wp_parse_url(self::issuer(),PHP_URL_PATH);
        $home=rtrim((string)wp_parse_url(home_url(),PHP_URL_PATH),'/');
        if($uri===$home.'/.well-known/oauth-authorization-server'.$issuer || $uri==='/.well-known/oauth-authorization-server'.$issuer){nocache_headers();wp_send_json(self::metadata());}
    }
    public static function routes(){
        register_rest_route('sitecore/v1','/oauth/resource',array('methods'=>'GET','permission_callback'=>'__return_true','callback'=>function(){return self::resource_metadata();}));
        register_rest_route('sitecore/v1','/oauth/metadata',array('methods'=>'GET','permission_callback'=>'__return_true','callback'=>function(){return self::metadata();}));
        foreach(array('token','revoke') as $action)register_rest_route('sitecore/v1','/oauth/'.$action,array('methods'=>'POST','permission_callback'=>function(){return is_ssl()?true:new WP_Error('tls_required','HTTPS richiesto.',array('status'=>403));},'callback'=>array(__CLASS__,$action)));
    }
    public static function register_client($name,$redirects){
        if(!SCC_Developer_Permissions::allowed('sitecore_mcp_manage'))return new WP_Error('forbidden','Account non autorizzato dall’HUB.');
        if(!$name||strlen($name)>120||!is_array($redirects)||count($redirects)<1||count($redirects)>4)return new WP_Error('invalid_client','Nome e URL callback richiesti.');
        foreach($redirects as $url){$p=wp_parse_url($url);if(strlen($url)>2000||!$p||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['fragment'])||isset($p['user'])||isset($p['pass']))return new WP_Error('invalid_redirect','Callback HTTPS esatto richiesto, senza frammenti o credenziali.');}
        global $wpdb;$id='scc_'.self::random();$ok=$wpdb->insert(self::table('clients'),array('client_id'=>$id,'name'=>sanitize_text_field($name),'redirects'=>wp_json_encode(array_values($redirects)),'created_at'=>gmdate('Y-m-d H:i:s')));
        return $ok?$id:new WP_Error('storage_error','Registrazione non riuscita.');
    }
    public static function client($id){global $wpdb;if(!is_string($id)||strlen($id)>64)return null;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('clients').' WHERE client_id=%s AND revoked=0',$id));}
    public static function authorize(){
        if(!is_ssl())wp_die('HTTPS richiesto.','Accesso negato',array('response'=>403));
        if(!is_user_logged_in()){auth_redirect();return;}
        if(!SCC_Developer_Permissions::allowed())wp_die('Account non autorizzato dall’HUB.','Accesso negato',array('response'=>403));
        $p=wp_unslash($_SERVER['REQUEST_METHOD']==='POST'?$_POST:$_GET);$client=self::client($p['client_id']??'');
        $redirect=is_string($p['redirect_uri']??null)?$p['redirect_uri']:'';
        if(!$client||!in_array($redirect,json_decode($client->redirects,true),true))wp_die('Client o callback non valido.','Richiesta non valida',array('response'=>400));
        $scope=preg_split('/\s+/',trim((string)($p['scope']??'')));$challenge=$p['code_challenge']??'';
        if(($p['response_type']??'')!=='code'||($p['code_challenge_method']??'')!=='S256'||!is_string($challenge)||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$challenge)||($p['resource']??'')!==self::endpoint()||!in_array('sitecore:mcp',$scope,true)||array_diff($scope,array('sitecore:mcp','offline_access'))||!is_string($p['state']??'')||strlen($p['state']??'')>1024)wp_die('Parametri OAuth non validi.','Richiesta non valida',array('response'=>400));
        nocache_headers();header('Referrer-Policy: no-referrer');header("Content-Security-Policy: frame-ancestors 'none'; form-action 'self'");
        if($_SERVER['REQUEST_METHOD']==='POST'){
            check_admin_referer('scc_oauth_consent');
            if(($p['consent']??'')!=='allow'){$url=add_query_arg(array('error'=>'access_denied','state'=>$p['state']??''),$redirect);wp_redirect($url,302,'SiteCore');exit;}
            $code=self::random();$family=self::random();$token=array('token_hash'=>self::hash($code),'kind'=>'code','family'=>$family,'client_id'=>$client->client_id,'user_id'=>get_current_user_id(),'resource'=>self::endpoint(),'redirect_uri'=>$redirect,'challenge'=>$challenge,'expires'=>time()+120);
            $token['scope']=implode(' ',array_values(array_unique($scope)));
            global $wpdb;if(!$wpdb->insert(self::table('tokens'),$token))wp_die('Impossibile creare l’autorizzazione.');
            wp_redirect(add_query_arg(array('code'=>$code,'state'=>$p['state']??'','iss'=>self::issuer()),$redirect),302,'SiteCore');exit;
        }
        echo '<!doctype html><html lang="it"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Autorizza SiteCore MCP</title><body><main><h1>Collega '.esc_html($client->name).'</h1><p>Stai autorizzando l’accesso MCP a '.esc_html(home_url()).'. Il client potrà leggere skill e memoria tecnica e usare gli strumenti disponibili per contenuti WordPress, moduli, prodotti e traduzioni, anche in scrittura, nei limiti dei tuoi permessi WordPress e dell’abilitazione HUB.</p><p>Utente: '.esc_html(wp_get_current_user()->user_login).'</p><p>Callback: '.esc_html($redirect).'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        foreach(array('client_id','redirect_uri','response_type','code_challenge','code_challenge_method','resource','scope','state') as $key)echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($p[$key]??'').'">';
        echo '<input type="hidden" name="action" value="scc_oauth_authorize">';wp_nonce_field('scc_oauth_consent');echo '<button name="consent" value="allow">Autorizza accesso MCP</button> <button name="consent" value="deny">Annulla</button></form></main></body></html>';exit;
    }
    public static function token(WP_REST_Request $request){
        if(!self::rate_limit())return self::error('temporarily_unavailable',429);
        $p=$request->get_body_params();if(!$p)$p=$request->get_json_params();if(!is_array($p)||strlen($request->get_body())>8000)return self::error('invalid_request');
        $client=self::client($p['client_id']??'');if(!$client)return self::error('invalid_client',401);
        $grant=$p['grant_type']??'';if(!in_array($grant,array('authorization_code','refresh_token'),true))return self::error('unsupported_grant_type');
        $raw=$p[$grant==='authorization_code'?'code':'refresh_token']??'';if(!is_string($raw)||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$raw))return self::error();
        global $wpdb;$table=self::table('tokens');$hash=self::hash($raw);$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE token_hash=%s",$hash));
        if(!$row||$row->client_id!==$client->client_id||$row->kind!==($grant==='authorization_code'?'code':'refresh')||$row->revoked||$row->expires<time()||!SCC_Developer_Permissions::allowed('sitecore_mcp_access',(int)$row->user_id)||$row->resource!==self::endpoint()||($p['resource']??'')!==self::endpoint())return self::error();
        if($grant==='authorization_code'){$v=$p['code_verifier']??'';if(!is_string($v)||!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D',$v)||!hash_equals($row->challenge,rtrim(strtr(base64_encode(hash('sha256',$v,true)),'+/','-_'),'='))||($p['redirect_uri']??'')!==$row->redirect_uri)return self::error();}
        if($row->consumed){$wpdb->update($table,array('revoked'=>1),array('family'=>$row->family));return self::error();}
        $wpdb->query('START TRANSACTION');
        $n=$wpdb->query($wpdb->prepare("UPDATE $table SET consumed=1 WHERE token_hash=%s AND consumed=0 AND revoked=0 AND expires>=%d",$hash,time()));
        if($n!==1){$wpdb->query('ROLLBACK');$wpdb->update($table,array('revoked'=>1),array('family'=>$row->family));return self::error();}
        $access=self::random();$refresh=self::random();$ok=true;
        $values=array('access'=>$access);if(in_array('offline_access',explode(' ',$row->scope),true))$values['refresh']=$refresh;
        foreach($values as $kind=>$value){$ok=$ok&&(bool)$wpdb->insert($table,array('token_hash'=>self::hash($value),'kind'=>$kind,'family'=>$row->family,'client_id'=>$row->client_id,'user_id'=>$row->user_id,'resource'=>$row->resource,'redirect_uri'=>'','challenge'=>'','scope'=>$row->scope,'expires'=>time()+($kind==='access'?900:2592000)));}
        if(!$ok){$wpdb->query('ROLLBACK');return self::error('server_error',503);}$wpdb->query('COMMIT');
        $out=array('access_token'=>$access,'token_type'=>'Bearer','expires_in'=>900,'scope'=>$row->scope);if(isset($values['refresh']))$out['refresh_token']=$refresh;
        return new WP_REST_Response($out,200,array('Cache-Control'=>'no-store','Pragma'=>'no-cache'));
    }
    public static function user_from_token($raw){
        if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$raw))return 0;
        global $wpdb;$t=self::table('tokens');$c=self::table('clients');$r=$wpdb->get_row($wpdb->prepare("SELECT t.user_id,t.resource FROM $t t JOIN $c c ON t.client_id=c.client_id WHERE t.token_hash=%s AND t.kind='access' AND t.revoked=0 AND c.revoked=0 AND t.expires>=%d",self::hash($raw),time()));
        return $r&&$r->resource===self::endpoint()&&SCC_Developer_Permissions::allowed('sitecore_mcp_access',(int)$r->user_id)?(int)$r->user_id:0;
    }
    public static function revoke(WP_REST_Request $request){
        if(!self::rate_limit())return self::error('temporarily_unavailable',429);
        $client=self::client($request['client_id']);$raw=$request['token'];if(!$client||!is_string($raw)||strlen($raw)>128)return self::error('invalid_client',401);
        global $wpdb;$t=self::table('tokens');$r=$wpdb->get_row($wpdb->prepare("SELECT family FROM $t WHERE token_hash=%s AND client_id=%s",self::hash($raw),$client->client_id));
        if($r)$wpdb->update($t,array('revoked'=>1),array('family'=>$r->family));return new WP_REST_Response(new stdClass(),200,array('Cache-Control'=>'no-store'));
    }
}
SCC_MCP_OAuth::boot();
