<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
$league = preg_replace('/[^a-z0-9._-]/i','', $_GET['league'] ?? '');
$resource = $_GET['resource'] ?? '';
$team = preg_replace('/[^a-zA-Z0-9._-]/','', $_GET['team'] ?? '');
$name = trim($_GET['name'] ?? '');
$allowed = ['bra.1','bra.2','eng.1','esp.1','ger.1','ita.1','fra.1','por.1','ned.1'];
if (!in_array($league,$allowed,true)) { http_response_code(400); echo json_encode(['error'=>'league']); exit; }
function http_json($url){
  $headers="User-Agent: BnhHub/1.1\r\nAccept: application/json\r\n";
  if(function_exists('curl_init')){
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>[$headers]]);$out=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($out!==false&&$code>=200&&$code<300)return $out;
  }
  $ctx=stream_context_create(['http'=>['timeout'=>15,'header'=>$headers]]);$out=@file_get_contents($url,false,$ctx);return $out===false?false:$out;
}
function norm_name($s){$s=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',(string)$s);$s=strtolower($s);$s=preg_replace('/\b(fc|football club|futebol clube|club de futebol|club)\b/','',$s);return trim(preg_replace('/[^a-z0-9]+/',' ', $s));}
function date_ymd($ts){return gmdate('Ymd',$ts);}
if($resource==='logo'){
  // Fonte principal: oGol. A página de cada equipe disponibiliza a identidade
  // visual do clube; extraímos a imagem da própria página para que o escudo
  // seja atualizado automaticamente quando um novo time for adicionado.
  $slug=trim(strtolower(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name)));
  $slug=preg_replace('/[^a-z0-9]+/','-', $slug);
  $slug=trim($slug,'-');
  $ogolCandidates=[];
  if($slug!==''){
    $ogolCandidates[]='https://www.ogol.com.br/equipe/'.rawurlencode($slug);
    // Alguns nomes comuns aparecem no oGol com grafia reduzida.
    $aliases=[
      'atletico-paranaense'=>'athletico-paranaense',
      'athletico-pr'=>'athletico-paranaense',
      'internazionale'=>'internazionale',
      'inter-milan'=>'internazionale',
      'paris-saint-germain'=>'psg',
      'psg'=>'psg',
      'manchester-united'=>'manchester-united',
      'manchester-city'=>'manchester-city',
      'red-bull-bragantino'=>'red-bull-bragantino'
    ];
    if(isset($aliases[$slug])) $ogolCandidates[]='https://www.ogol.com.br/equipe/'.rawurlencode($aliases[$slug]);
  }
  $ogolBest=null;
  foreach(array_unique($ogolCandidates) as $ogolUrl){
    $html=http_json($ogolUrl);
    if($html===false || strlen($html)<500) continue;
    // Primeiro tenta OpenGraph; depois imagens que tenham o nome do clube no alt/title.
    if(preg_match('/<meta[^>]+property=[\"\']og:image[\"\'][^>]+content=[\"\']([^\"\']+)[\"\']/i',$html,$m)){
      $cand=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
      if(str_starts_with($cand,'//')) $cand='https:'.$cand;
      if(preg_match('#^https?://#i',$cand)){$ogolBest=$cand;break;}
    }
    if(preg_match_all('/<img\b[^>]*(?:src|data-src|data-original)=[\"\']([^\"\']+)[\"\'][^>]*>/i',$html,$ms)){
      foreach($ms[1] as $cand){
        $cand=html_entity_decode($cand,ENT_QUOTES,'UTF-8');
        if(str_starts_with($cand,'//')) $cand='https:'.$cand;
        if(!preg_match('#^https?://#i',$cand)) continue;
        $lc=strtolower($cand);
        if(strpos($lc,'logo')!==false || strpos($lc,'equipa')!==false || strpos($lc,'escudo')!==false || strpos($lc,'team')!==false){$ogolBest=$cand;break 2;}
      }
    }
  }
  if($ogolBest){
    echo json_encode(['url'=>$ogolBest,'source'=>'oGol'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
  }
  // Fallback para não deixar o seletor sem escudo se o oGol estiver indisponível.
  $q=trim($name.' logo');
  $url='https://commons.wikimedia.org/w/api.php?action=query&generator=search&gsrsearch='.rawurlencode($q).'&gsrnamespace=6&gsrlimit=8&prop=imageinfo&iiprop=url|mime&iiurlwidth=220&format=json&origin=*';
  $raw=http_json($url);$best=null;$score=-1;
  if($raw!==false){$j=json_decode($raw,true);foreach(($j['query']['pages']??[]) as $pg){$title=$pg['title']??'';$info=$pg['imageinfo'][0]??[];$mime=$info['mime']??'';$t=strtolower($title);if(strpos($mime,'image/')!==0)continue;$sc=0;if(stripos($t,'logo')!==false)$sc+=8;if(stripos($t,'crest')!==false||stripos($t,'badge')!==false||stripos($t,'escudo')!==false)$sc+=7;if(stripos($t,norm_name($name))!==false)$sc+=3;if($sc>$score){$score=$sc;$best=$info['thumburl']??$info['url']??null;}}}
  echo json_encode(['url'=>$best,'source'=>'Wikimedia Commons fallback'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
}
if($resource==='teams')$path="$league/teams";
elseif($resource==='schedule' && $team!==''){
  if(str_starts_with($team,'static-')){
    $start=date_ymd(strtotime('-60 days'));$end=date_ymd(strtotime('+120 days'));
    $url='https://site.api.espn.com/apis/site/v2/sports/soccer/'.rawurlencode($league).'/scoreboard?dates='.$start.'-'.$end;
    $raw=http_json($url);if($raw===false){http_response_code(502);echo json_encode(['error'=>'upstream']);exit;}
    $j=json_decode($raw,true);$target=norm_name($name);$events=[];
    foreach(($j['events']??[]) as $ev){$found=false;foreach(($ev['competitions'][0]['competitors']??[]) as $c){$tn=$c['team']['displayName']??$c['team']['name']??'';if($target!==''&&($target===norm_name($tn)||strpos(norm_name($tn),$target)!==false||strpos($target,norm_name($tn))!==false)){$found=true;break;}}if($found)$events[]=$ev;}
    echo json_encode(['events'=>$events],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
  }
  // A ESPN possui uma rota específica para agenda do time que funciona melhor
  // pelo host site.web.api.espn.com. Mantemos o host site.api como fallback.
  $primaryUrl='https://site.web.api.espn.com/apis/site/v2/sports/soccer/all/teams/'.rawurlencode($team).'/schedule';
  $out=http_json($primaryUrl);
  if($out!==false){
    $j=json_decode($out,true);
    if(is_array($j)) { echo json_encode($j,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
  }
  $path="$league/teams/".rawurlencode($team)."/schedule";
}else{http_response_code(400);echo json_encode(['error'=>'resource']);exit;}
$url='https://site.api.espn.com/apis/site/v2/sports/soccer/'.$path;
$out=http_json($url);if($out===false){http_response_code(502);echo json_encode(['error'=>'upstream']);exit;}echo $out;
