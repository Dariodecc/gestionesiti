<?php
defined('ABSPATH') || exit;
/** Shared service contract. No protocol or database dependencies. */
final class SCC_Dev_Schema {
    public static function text($max=2000) { return array('type'=>'string','maxLength'=>$max); }
    public static function strings($count=40) { return array('type'=>'array','maxItems'=>$count,'items'=>self::text(300)); }
    public static function object($properties,$required=array()) {return array('type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false);}
    public static function context() {
        $p=array();foreach(array('architecture','current_state','coding_conventions','environment','versions') as $key)$p[$key]=self::text(3000);
        foreach(array('implemented_features','important_files','skills_used') as $key)$p[$key]=self::strings();
        $p['rolling_summary']=self::text(8000);return self::object($p);
    }
    public static function record($kind) {
        $p=array('title'=>array('type'=>'string','minLength'=>1,'maxLength'=>200),'related_files'=>self::strings(20));
        if($kind==='decisions'){$p['decision']=self::text(4000);$p['reason']=self::text(3000);$p['status']=array('type'=>'string','enum'=>array('accepted','proposed','superseded'));$p['related_change']=array('type'=>'integer','minimum'=>1);$required=array('title','decision','reason');}
        elseif($kind==='changes'){$p['summary']=self::text(4000);$p['git_commit']=array('type'=>'string','pattern'=>'^[a-fA-F0-9]{7,64}$');$required=array('title','summary');}
        else{$p['description']=self::text(4000);$p['severity']=array('type'=>'string','enum'=>array('low','medium','high','critical'));$required=array('title','description','severity');}
        return self::object($p,$required);
    }
    public static function validate($value,$schema) {
        $r=rest_validate_value_from_schema($value,$schema,'input');
        if(is_wp_error($r))return $r;
        $json=wp_json_encode($value);
        if(strlen($json)>40000)return new WP_Error('too_large','Contenuto troppo lungo.',array('status'=>400));
        // Refuse common credential formats; never echo rejected values.
        if(preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----|Authorization\s*:\s*(Basic|Bearer)\s+\S+|(?:password|secret|api[_-]?key|token)\s*[=:]\s*["\x27]?[A-Za-z0-9_\-\/+=]{16,}/i',$json))return new WP_Error('secret_detected','Rimuovi credenziali e segreti dal contesto.',array('status'=>400));
        return true;
    }
}
