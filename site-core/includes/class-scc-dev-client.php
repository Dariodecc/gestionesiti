<?php
defined('ABSPATH') || exit;
final class SCC_Dev_Client {
    public static function base(){return 'https://gestionesiti.growbe.cloud/wp-json/scchub/v1/developer/';}
    public static function key(){return hash('sha256',wp_salt('auth').'|sitecore-development-binding',true);}
    public static function credential(){
        $stored=get_option('scc_dev_binding',array());
        if(empty($stored['secret'])||!function_exists('openssl_decrypt'))return '';
        $raw=base64_decode($stored['secret'],true);if($raw===false||strlen($raw)<29)return '';
        return (string)openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'site:'.(int)$stored['site_id']);
    }
    /** $system=true: chiamata dal comando autenticato dell'HUB (nessun utente collegato); altrimenti serve l'autorizzazione dell'account. */
    public static function bind($system=false){
        if(!$system&&!SCC_Developer_Permissions::allowed('sitecore_mcp_manage'))return new WP_Error('forbidden','Account non autorizzato dall’HUB.');
        if(!function_exists('openssl_encrypt'))return new WP_Error('crypto_unavailable','OpenSSL richiesto per il collegamento.');
        $r=wp_safe_remote_post(self::base().'bind',array('timeout'=>8,'redirection'=>0,'limit_response_size'=>20000,'headers'=>array('X-Licence-Key'=>SCC_Hub::chiave(),'X-Site-Url'=>home_url(),'Accept'=>'application/json','Content-Type'=>'application/json'),'body'=>'{}'));
        if(is_wp_error($r))return new WP_Error('hub_unavailable','GestioneSiti non raggiungibile.');
        $data=json_decode(wp_remote_retrieve_body($r),true);
        if(wp_remote_retrieve_response_code($r)!==201||empty($data['site_id'])||!preg_match('/^scb_[a-f0-9]{64}$/D',$data['credential']??''))return new WP_Error('binding_rejected','Collegamento rifiutato. Abilitalo o reimpostalo dalla scheda Developer in GestioneSiti.');
        $iv=random_bytes(12);$tag='';$encrypted=openssl_encrypt($data['credential'],'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,'site:'.(int)$data['site_id']);
        if($encrypted===false)return new WP_Error('crypto_failed','Cifratura non riuscita.');
        update_option('scc_dev_binding',array('site_id'=>(int)$data['site_id'],'secret'=>base64_encode($iv.$tag.$encrypted)),false);return array('site_id'=>(int)$data['site_id']);
    }
    public static function request($kind,$method='GET',$data=array(),$key=''){
        if(!SCC_Developer_Permissions::allowed())return new WP_Error('forbidden','Account non autorizzato dall’HUB.');
        if(!in_array($kind,array('context','decisions','changes','issues','skills','health','audit','bootstrap'),true))return new WP_Error('invalid_operation','Operazione non disponibile.');
        $binding=get_option('scc_dev_binding',array());$token=self::credential();
        if(!$token||empty($binding['site_id']))return new WP_Error('not_bound','Collega questo sito a GestioneSiti dalla sezione Developer.');
        $url=self::base().'sites/'.(int)$binding['site_id'].'/'.$kind;
        $args=array('method'=>$method,'timeout'=>$kind==='audit'?2:8,'redirection'=>0,'limit_response_size'=>500000,'headers'=>array('X-Sitecore-Binding'=>$token,'X-Site-Url'=>home_url(),'X-Sitecore-Actor'=>'wp-user:'.get_current_user_id(),'Accept'=>'application/json','Content-Type'=>'application/json'));
        if($key)$args['headers']['Idempotency-Key']=$key;
        if($method==='GET')$url=add_query_arg($data,$url);else $args['body']=wp_json_encode($data);
        try{$response=wp_safe_remote_request($url,$args);}catch(Throwable $e){return new WP_Error('hub_unavailable','GestioneSiti non raggiungibile.');}
        if(is_wp_error($response))return new WP_Error('hub_unavailable','GestioneSiti non raggiungibile. Nessun salvataggio confermato.');
        $status=wp_remote_retrieve_response_code($response);$out=json_decode(wp_remote_retrieve_body($response),true);
        if($status<200||$status>=300||!is_array($out))return new WP_Error('hub_rejected',in_array($status,array(401,403),true)?'Collegamento non autorizzato o revocato.':($status===409?'Conflitto: rileggi i dati prima di salvare.':'GestioneSiti ha rifiutato la richiesta.'),array('status'=>$status));
        return $out;
    }
    /** Manda all'HUB il manifest con il permesso monouso: l'HUB lo inoltra al bridge, che lo scambia con la credenziale permanente. */
    public static function opencode_grant($manifest){
        $r=wp_safe_remote_post(self::base().'opencode-grant',array('timeout'=>25,'redirection'=>0,'limit_response_size'=>20000,'headers'=>array('X-Licence-Key'=>SCC_Hub::chiave(),'X-Site-Url'=>home_url(),'Accept'=>'application/json','Content-Type'=>'application/json'),'body'=>wp_json_encode(array('manifest'=>$manifest))));
        if(is_wp_error($r))return new WP_Error('hub_unavailable','GestioneSiti non raggiungibile.');
        $code=wp_remote_retrieve_response_code($r);$out=json_decode(wp_remote_retrieve_body($r),true);
        if($code!==200||empty($out['ok']))return new WP_Error('grant_rejected',is_array($out)&&!empty($out['message'])?(string)$out['message']:'Collegamento OpenCode rifiutato (HTTP '.$code.').');
        return $out;
    }
    public static function health(){
        $user=wp_get_current_user();$credentials=WP_Application_Passwords::get_user_application_passwords($user->ID);
        $data=array('enabled'=>SCC_Developer_Permissions::allowed(),'endpoint'=>rest_url('sitecore/v1/mcp'),'sitecore_version'=>SCC_VERSION,'developer_user'=>$user->user_login,'last_authenticated_at'=>(string)get_option('scc_mcp_last_authenticated',''),'credential_count'=>count($credentials),'binding_valid'=>self::credential()!=='');
        $out=self::request('health','POST',$data);return is_wp_error($out)?array('local'=>$data,'hub_reachable'=>false,'message'=>$out->get_error_message()):array_merge(array('local'=>$data),$out);
    }
}
