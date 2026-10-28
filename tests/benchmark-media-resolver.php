<?php
/** Disposable GitHub Actions-only WP/MySQL performance benchmark. */
if (getenv('CI') !== 'true' || getenv('ESP_ALLOW_DISPOSABLE_DB') !== '1' ||
    !getenv('ESP_WP_ROOT')) { fwrite(STDERR, "Refusing non-CI execution\n"); exit(2); }
define('WP_INSTALLING', true);
require rtrim(getenv('ESP_WP_ROOT'), '/') . '/wp-load.php';
wp_installing(false);
if (strpos(DB_NAME, 'esp_') !== 0) { exit(2); }
require __DIR__ . '/../includes/class-esp-media-file-resolver.php';
function fail_perf($s) { fwrite(STDERR, "BENCH ERROR: $s\n"); exit(1); }
function distribution($values) {
    sort($values, SORT_NUMERIC);
    $p = static function ($q) use ($values) {
        $idx = (count($values) - 1) * $q;
        $lo = (int) floor($idx); $hi = min(count($values)-1, $lo+1);
        return round($values[$lo] + ($values[$hi]-$values[$lo])*($idx-$lo), 4);
    };
    return ['n'=>count($values),'avg_ms'=>round(array_sum($values)/count($values),4),
        'p50_ms'=>$p(.5),'p95_ms'=>$p(.95),'max_ms'=>round(max($values),4)];
}
function seed_perf($target) {
    global $wpdb;
    $old = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}
        WHERE post_type='attachment' AND post_name LIKE 'esp42perf-%'");
    if ($old > $target) { fail_perf('Shrinking dataset is not supported'); }
    $date='2026-10-28 00:00:00';
    for ($start=$old; $start<$target; $start+=250) {
        $count=min(250,$target-$start); $rows=[];
        for ($j=1;$j<=$count;$j++) {
            $slug='esp42perf-'.str_pad((string)($start+$j),7,'0',STR_PAD_LEFT);
            $rows[]="('$date','$date','','$slug','','$slug','$date','$date','inherit','attachment')";
        }
        $n=$wpdb->query("INSERT INTO {$wpdb->posts}
            (post_date,post_date_gmt,post_content,post_title,post_excerpt,post_name,
             post_modified,post_modified_gmt,post_status,post_type)
            VALUES ".implode(',',$rows));
        if ($n !== $count) { fail_perf('posts insert '.$wpdb->last_error); }
        $first=(int)$wpdb->insert_id; $values=[];
        for ($j=0;$j<$count;$j++) {
            $id=$first+$j;
            $file='2026/perf/item-'.str_pad((string)($start+$j+1),7,'0',STR_PAD_LEFT).'.jpg';
            $values[]="($id,'_wp_attached_file','$file')";
        }
        if ($wpdb->query("INSERT INTO {$wpdb->postmeta}
            (post_id,meta_key,meta_value) VALUES ".implode(',',$values)) !== $count) {
            fail_perf('postmeta insert '.$wpdb->last_error);
        }
    }
    $wpdb->query("ANALYZE TABLE {$wpdb->posts}");
    $wpdb->query("ANALYZE TABLE {$wpdb->postmeta}");
    return ['attachments_seeded'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}
        WHERE post_type='attachment' AND post_name LIKE 'esp42perf-%'"),
        'postmeta_count'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}")];
}
function baseline_perf($file) {
    global $wpdb;
    $dir=wp_upload_dir();
    $relative=str_replace(rtrim($dir['basedir'],'/').'/','',$file);
    // Exact original lookup in main HEAD (prior to #42).
    return $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta}
        WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
        $relative));
}
function plans_perf($relative) {
    global $wpdb;
    $old=$wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta}
       WHERE meta_key='_wp_attached_file' AND meta_value=%s LIMIT 1",$relative);
    $ref=new ReflectionClass(ESP_Media_File_Resolver::class);
    $candidate=$ref->getMethod('candidate_paths')->invoke(null,$relative);
    if (!is_array($candidate)) { fail_perf('No candidate paths'); }
    $p=$candidate['paths'];$l=$candidate['likes'];
    $in=implode(', ',array_fill(0,count($p),'%s'));
    $like=implode(' OR ',array_fill(0,count($l),'pm.meta_value LIKE %s'));
    $where="pm.meta_value IN ($in)";
    if ($like!=='') {$where.=" OR $like";}
    $sql="SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
       INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
          AND p.post_type='attachment'
       WHERE pm.meta_key=%s AND ($where) LIMIT 65";
    $new=$wpdb->prepare($sql,array_merge(['_wp_attached_file'],$p,$l));
    $e1=$wpdb->get_results('EXPLAIN '.$old,ARRAY_A);
    $e2=$wpdb->get_results('EXPLAIN '.$new,ARRAY_A);
    if ($wpdb->last_error!=='') { fail_perf('EXPLAIN: '.$wpdb->last_error); }
    return ['baseline'=>$e1,'pr62'=>$e2,'candidate_paths'=>count($p),
        'like_predicates'=>count($l)];
}
function measure_perf() {
    global $wpdb;
    $root=rtrim(wp_upload_dir()['basedir'],'/');
    $files=[
      'public_original'=>"$root/esp31-public.jpg",
      'protected_original'=>"$root/esp31-protected.jpg",
      'public_thumbnail'=>"$root/esp31-public-150x150.jpg",
      'unregistered'=>"$root/esp42-perf-unregistered.jpg",
    ];
    file_put_contents($files['unregistered'],'ESP42_PERF_UNREGISTERED');
    $resolver=new ESP_Media_File_Resolver();$cases=[];
    foreach($files as $name=>$file) {
        if(!is_file($file)){fail_perf('Missing fixture: '.$file);}
        $times=['baseline'=>[],'pr62'=>[]];$querycounts=['baseline'=>[],'pr62'=>[]];
        $results=[];
        for($i=0;$i<6;$i++){baseline_perf($file);$resolver->resolve($file);}
        for($i=0;$i<36;$i++) {
            $order=$i%2===0?['baseline','pr62']:['pr62','baseline'];
            foreach($order as $mode) {
                $before=$wpdb->num_queries;$start=hrtime(true);
                $result=$mode==='baseline'?baseline_perf($file):$resolver->resolve($file);
                $times[$mode][]=(hrtime(true)-$start)/1000000;
                $querycounts[$mode][]=$wpdb->num_queries-$before;
                $results[$mode]=$result;
            }
        }
        if ($wpdb->last_error!=='') { fail_perf('SQL failure: '.$wpdb->last_error); }
        $baseline=distribution($times['baseline']);
        $pr=distribution($times['pr62']);
        $cases[$name]=['baseline'=>$baseline,'pr62'=>$pr,
           'p95_ratio'=>round($pr['p95_ms']/max(.0001,$baseline['p95_ms']),2),
           'sql_count_mean'=>[
             'baseline'=>round(array_sum($querycounts['baseline'])/36,2),
             'pr62'=>round(array_sum($querycounts['pr62'])/36,2)],
           'outcomes'=>$results];
    }
    return ['iterations_per_case'=>36,'cases'=>$cases,
      'explain_public_original'=>plans_perf('esp31-public.jpg'),
      'peak_memory_mb'=>round(memory_get_peak_usage(true)/1048576,2)];
}
$mode=$argv[1]??'';$size=(int)($argv[2]??0);
if(!in_array($size,[10000,100000],true)){fail_perf('Expect 10k or 100k');}
if($mode==='seed'){$result=seed_perf($size);}
elseif($mode==='measure'){$result=measure_perf();}
else{fail_perf('Expected seed or measure');}
echo json_encode(['mode'=>$mode,'fixture_size'=>$size,'result'=>$result],
  JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
