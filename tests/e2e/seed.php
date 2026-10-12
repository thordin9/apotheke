<?php
// Seed a running instance with demo data for e2e checks and screenshots. Usage: php tests/e2e/seed.php BASE_URL
require dirname(__DIR__) . '/lib.php';
$c = new Client($argv[1]); $tok = $c->csrf();
$L = []; foreach (['box 1023','box 2','Garage shelf','Kitchen drawer','Attic'] as $n) $L[$n] = $c->api('/locations', ['csrf'=>$tok,'name'=>$n])['json']['id'];
$C = []; foreach (['Tools','Electronics','Kitchen','Camping','Books'] as $n) $C[$n] = $c->api('/categories', ['csrf'=>$tok,'name'=>$n])['json']['id'];
$c->post('/locations/'.$L['box 1023'].'/default', ['csrf'=>$tok,'on'=>1]);
$c->post('/categories/'.$C['Tools'].'/default', ['csrf'=>$tok,'on'=>1]);
function pic($r,$g,$b){ $im=imagecreatetruecolor(900,900); for($y=0;$y<900;$y++){imageline($im,0,$y,899,$y,imagecolorallocate($im,min(255,$r+$y/9),min(255,$g+$y/12),min(255,$b+$y/15)));}
 imagefilledellipse($im,450,470,460,460,imagecolorallocatealpha($im,255,255,255,70)); imagefilledrectangle($im,300,330,600,610,imagecolorallocate($im,(int)($r*.5),(int)($g*.5),(int)($b*.5))); ob_start(); imagejpeg($im,null,85); return ob_get_clean(); }
$items=[['Cordless drill','box 1023','Tools',1,[200,80,40]],['HDMI cables','Kitchen drawer','Electronics',4,[40,90,200]],['Camping stove','Attic','Camping',1,[30,140,90]],
 ['Cast iron pan','Kitchen drawer','Kitchen',2,[90,60,50]],['Raspberry Pi 4','box 2','Electronics',3,[180,40,90]],['Tent (2 person)','Attic','Camping',1,[60,120,60]],
 ['Screwdriver set','Garage shelf','Tools',1,[220,160,30]],['The Odyssey','box 2','Books',1,[120,90,160]],['Extension cord with a really long descriptive name','Garage shelf','Electronics',2,null],['Headlamp','box 1023','Camping',5,[20,20,40]]];
foreach($items as [$n,$l,$cat,$q,$col]) $c->api('/save',['csrf'=>$tok,'name'=>$n,'location_id'=>$L[$l],'category_id'=>$C[$cat],'quantity'=>$q,'notes'=>$n==='Cordless drill'?"Makita 18V, 2 batteries.\nBought 2024.":''],$col?['photo'=>jpg_file(pic(...$col))]:[]);
echo "seeded\n";
