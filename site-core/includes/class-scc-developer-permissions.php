<?php
/** Per-account authorization; legacy class name retained only for internal compatibility. */
defined('ABSPATH') || exit;
final class SCC_Developer_Permissions {
    const CAPS=array('sitecore_mcp_access','sitecore_mcp_manage','sitecore_dev_context_read','sitecore_dev_context_write','sitecore_skill_read');
    private static $checks=array();
    public static function boot(){
        add_action('init',array(__CLASS__,'migrate'),2);
        add_filter('map_meta_cap',array(__CLASS__,'protect_account'),10,4);
        add_action('scc_hub_battito',array(__CLASS__,'sync_users'),20);
        add_action('admin_post_scc_mcp_sync',function(){
            if(!current_user_can('manage_options'))wp_die('Accesso negato.',403);
            check_admin_referer('scc_mcp_sync');$ok=self::sync_users();
            wp_safe_redirect(admin_url('admin.php?page=scc-developer&synced='.($ok?'1':'0')));exit;
        });
    }
    public static function migrate(){
        if(get_option('scc_mcp_account_migration')==='1')return;
        // Keep existing administrative rights, but never carry over role-based MCP grants.
        foreach(get_users(array('role'=>'developer')) as $u){$u->add_role('administrator');$u->remove_role('developer');}
        remove_role('developer');
        foreach(wp_roles()->role_objects as $role){foreach(self::CAPS as $cap)$role->remove_cap($cap);$role->remove_cap('scc_developer');}
        update_option('scc_mcp_enabled',false,false);
        update_option('scc_mcp_account_migration','1',false);
    }
    public static function identity($u){return array('id'=>(int)$u->ID,'login'=>$u->user_login,'registered'=>$u->user_registered);}
    public static function protect_account($caps,$cap,$actor,$args){
        if(!in_array($cap,array('edit_user','delete_user','remove_user','promote_user','create_app_password','list_app_passwords','read_app_password','edit_app_password','delete_app_passwords','delete_app_password'),true)||empty($args[0])||(int)$actor===(int)$args[0])return $caps;
        $target=self::policy((int)$args[0]);
        if(!empty($target['granted'])&&!self::allowed('sitecore_mcp_manage',(int)$actor))return array('do_not_allow');
        return $caps;
    }
    private static function call($route,$body){
        if(!SCC_Hub::chiave())return null;
        $r=wp_safe_remote_post('https://gestionesiti.growbe.cloud/wp-json/scchub/v1/'.$route,array('timeout'=>5,'redirection'=>0,'limit_response_size'=>10000,'headers'=>array('X-Licence-Key'=>SCC_Hub::chiave(),'X-Site-Url'=>home_url(),'Content-Type'=>'application/json'),'body'=>wp_json_encode($body)));
        if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200)return null;
        $v=json_decode(wp_remote_retrieve_body($r),true);return is_array($v)?$v:null;
    }
    public static function policy($id){
        $id=(int)$id;if(array_key_exists($id,self::$checks))return self::$checks[$id];
        $u=$id?get_userdata($id):null;
        if(!$u||!is_user_member_of_blog($id))return self::$checks[$id]=array('enabled'=>false,'allowed'=>false);
        // Due attivatori indipendenti nell'HUB: l'account sviluppatore dedicato e' governato dall'accesso SVILUPPO (developer-access/check),
        // gli account del cliente dall'accesso CLIENTE (mcp-access/check). Spegnere l'uno non tocca l'altro.
        $r=self::call(SCC_Developer_Principal::is($id)?'developer-access/check':'mcp-access/check',array('user'=>self::identity($u)));
        // No persistent authorization cache: revocation takes effect on the next request.
        return self::$checks[$id]=($r?:array('enabled'=>false,'allowed'=>false));
    }
    public static function allowed($cap='sitecore_mcp_access',$user_id=null){
        $id=$user_id===null?get_current_user_id():(int)$user_id;if(!$id)return false;
        $p=self::policy($id);if(empty($p['enabled'])||empty($p['allowed']))return false;
        return in_array($cap,self::CAPS,true)||user_can($id,$cap);
    }
    public static function sync_users(){
        $batch=wp_generate_uuid4();$page=1;
        do{
            $users=get_users(array('number'=>200,'paged'=>$page,'orderby'=>'ID','order'=>'ASC'));
            $rows=array();foreach($users as $u)$rows[]=array_merge(self::identity($u),array('name'=>$u->display_name,'roles'=>array_values($u->roles)));
            $last=count($users)<200;
            // L'account sviluppatore non e' un account del cliente: non compare nell'elenco che l'HUB mostra per l'accesso cliente.
            $rows=array_values(array_filter($rows,function($row){return !SCC_Developer_Principal::is($row['id']);}));
            $r=self::call('mcp-access/inventory',array('batch'=>$batch,'page'=>$page,'last'=>$last,'users'=>$rows,'version'=>SCC_VERSION));
            if(!$r)return false;
            $page++;
        }while(!$last);
        return true;
    }
}
SCC_Developer_Permissions::boot();
