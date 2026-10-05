<?php
defined('ABSPATH') || exit;
/**
 * Account sviluppatore dedicato: il "principal" con cui OpenCode (tramite il bridge Growbe) usa l'MCP di questo sito.
 *
 * Perche' esiste: fino alla 2.16 ogni richiesta MCP, sviluppo compreso, dipendeva dall'unico interruttore MCP del cliente
 * (HUB: mcp-access/check). Spegnerlo per il cliente tagliava anche lo sviluppo e, spento per default, bloccava i siti nuovi.
 * Ora ci sono DUE attivatori indipendenti nell'HUB:
 *   - Sviluppo (Growbe)  -> governa QUESTO account, tramite developer-access/check;
 *   - Cliente            -> governa gli account WordPress del cliente, tramite mcp-access/check (invariato).
 *
 * L'account e' creato dal collegamento automatico (comando "opencode_link" nel battito), non ha una password utilizzabile
 * (casuale, mai mostrata), non puo' fare il login dalla bacheca ne' reimpostare la password: si autentica solo con
 * l'Application Password che il bridge ottiene scambiando il permesso monouso. Gli altri utenti non possono modificarlo.
 */
final class SCC_Developer_Principal {
    const META='_scc_developer_principal';
    const LOGIN='growbe-developer';
    public static function boot(){
        add_filter('wp_authenticate_user',array(__CLASS__,'block_interactive_login'),1,1);
        add_filter('allow_password_reset',array(__CLASS__,'block_password_reset'),10,2);
        add_filter('map_meta_cap',array(__CLASS__,'protect'),9,4);
    }
    public static function user_id(){
        $found=get_users(array('meta_key'=>self::META,'meta_value'=>'1','number'=>1,'fields'=>'ID'));
        return $found?(int)$found[0]:0;
    }
    public static function is($user_id){
        $id=(int)$user_id;
        return $id>0&&get_user_meta($id,self::META,true)==='1';
    }
    /** Crea l'account se manca e ne restituisce l'ID (o un WP_Error). Idempotente. */
    public static function ensure(){
        $id=self::user_id();if($id)return $id;
        $login=self::LOGIN;$n=1;
        while(username_exists($login)){
            $n++;if($n>20)return new WP_Error('principal_login','Nessun nome utente libero per l’account sviluppatore.');
            $login=self::LOGIN.'-'.$n;
        }
        $id=wp_insert_user(array(
            'user_login'=>$login,
            'user_pass'=>wp_generate_password(64,true,true),
            'user_email'=>'developer+'.substr(hash('sha256',home_url()),0,8).'@growbe.cloud',
            'display_name'=>'Growbe Developer',
            'role'=>'administrator',
        ));
        if(is_wp_error($id))return $id;
        update_user_meta($id,self::META,'1');
        return (int)$id;
    }
    public static function block_interactive_login($user){
        if($user instanceof WP_User&&self::is($user->ID))return new WP_Error('developer_account','Account riservato allo sviluppo: accesso solo tramite Application Password.');
        return $user;
    }
    public static function block_password_reset($allow,$user_id){
        return self::is($user_id)?new WP_Error('developer_account','Account riservato allo sviluppo.'):$allow;
    }
    /** Nessun altro utente (nemmeno un amministratore del cliente) puo' modificare, promuovere, eliminare l'account o gestirne le credenziali. */
    public static function protect($caps,$cap,$actor,$args){
        $guard=array('edit_user','delete_user','remove_user','promote_user','create_app_password','list_app_passwords','read_app_password','edit_app_password','delete_app_passwords','delete_app_password');
        if(!in_array($cap,$guard,true)||empty($args[0]))return $caps;
        $target=(int)$args[0];
        if(!$actor||(int)$actor===$target||!self::is($target))return $caps;
        return array('do_not_allow');
    }
}
SCC_Developer_Principal::boot();
