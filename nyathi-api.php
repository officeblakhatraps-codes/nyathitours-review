<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function input_json(): array {
    $raw = file_get_contents('php://input');
    $value = json_decode($raw ?: '{}', true);
    return is_array($value) ? $value : [];
}
function require_user(PDO $db, bool $mutating = true): array {
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) respond(401, ['ok'=>false,'error'=>'Please sign in again.']);
    if ($mutating) {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$sent || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) respond(419, ['ok'=>false,'error'=>'Your session expired. Sign in again.']);
    }
    $q=$db->prepare('SELECT id,email,role,display_name,active FROM users WHERE id=?');
    $q->execute([$_SESSION['user_id']]); $user=$q->fetch(PDO::FETCH_ASSOC);
    if (!$user || !$user['active']) { session_destroy(); respond(401,['ok'=>false,'error'=>'This account is inactive.']); }
    return $user;
}
function date_or_null($value): ?string {
    if ($value === null || $value === '') return null;
    $d=DateTime::createFromFormat('!Y-m-d',(string)$value);
    return $d && $d->format('Y-m-d') === $value ? $d->format('Y-m-d') : null;
}
function trip_row(array $r): array {
    $start=$r['start_date'] ?: '';
    $end=$r['end_date'] ?: '';
    $label=$start ? (new DateTime($start))->format('j M Y') . ($end && $end!==$start ? ' – ' . (new DateTime($end))->format('j M Y') : '') : 'Ask for next dates';
    $opts=$r['package_options'] ? json_decode($r['package_options'],true) : [];
    return ['id'=>(string)$r['id'],'name'=>$r['name'],'tag'=>$r['tag'],'summary'=>$r['summary'],'duration'=>$r['duration'],'price'=>(float)$r['price'],'priceText'=>$r['price_text'] ?: 'from R'.number_format((float)$r['price'],0),'image'=>$r['image_url'],'startDate'=>$start,'endDate'=>$end,'dateLabel'=>$label,'capacity'=>$r['capacity']===null?null:(int)$r['capacity'],'availableSeats'=>$r['available_seats']===null?null:(int)$r['available_seats'],'pickup'=>$r['pickup'],'detail'=>$r['detail'],'options'=>is_array($opts)?$opts:[],'visible'=>$r['visibility']==='published','visibility'=>$r['visibility']];
}
function public_trips(PDO $db): array {
    $sql="SELECT t.*,(SELECT COALESCE(SUM(b.seats),0) FROM bookings b WHERE b.trip_id=t.id AND b.departure_date=t.start_date AND b.status='accepted') AS booked FROM trips t WHERE t.visibility='published' ORDER BY t.start_date IS NULL,t.start_date,t.created_at DESC";
    $rows=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$r) $r['available_seats']=$r['capacity']===null?null:max(0,(int)$r['capacity']-(int)$r['booked']);
    return array_map('trip_row',$rows);
}
function load_dashboard(PDO $db): array {
    $tripRows=$db->query("SELECT t.*,(SELECT COALESCE(SUM(b.seats),0) FROM bookings b WHERE b.trip_id=t.id AND b.departure_date=t.start_date AND b.status='accepted') AS booked FROM trips t ORDER BY t.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach($tripRows as &$r) $r['available_seats']=$r['capacity']===null?null:max(0,(int)$r['capacity']-(int)$r['booked']);
    $bookings=$db->query("SELECT b.*,i.id AS invoice_id,i.invoice_number,i.total AS invoice_total,i.paid AS invoice_paid,i.deposit_required,i.status AS invoice_status FROM bookings b LEFT JOIN invoices i ON i.booking_id=b.id ORDER BY b.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $mappedBookings=[];
    foreach($bookings as $b) $mappedBookings[]=['id'=>(string)$b['id'],'requestNumber'=>$b['request_number'],'trip'=>$b['trip_id'],'packageLabel'=>$b['package_label'],'requestedPrice'=>(float)$b['requested_price'],'requestedDeposit'=>(float)$b['requested_deposit'],'date'=>$b['departure_date'],'seats'=>(int)$b['seats'],'name'=>$b['client_name'],'phone'=>$b['phone'],'email'=>$b['email'],'pickup'=>$b['pickup'],'notes'=>$b['notes'],'status'=>$b['status'],'finalPricePerPerson'=>$b['accepted_price']===null?null:(float)$b['accepted_price'],'invoiceId'=>$b['invoice_id']?(string)$b['invoice_id']:null];
    $invoices=$db->query("SELECT i.*,b.client_name,b.email,b.phone,b.trip_id,b.package_label,b.seats,b.request_number FROM invoices i JOIN bookings b ON b.id=i.booking_id ORDER BY i.issued_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $mappedInvoices=[];
    foreach($invoices as $i) $mappedInvoices[]=['id'=>(string)$i['id'],'bookingId'=>(string)$i['booking_id'],'number'=>$i['invoice_number'],'client'=>$i['client_name'],'contact'=>$i['email'] ?: $i['phone'],'trip'=>$i['trip_id'],'total'=>(float)$i['total'],'paid'=>(float)$i['paid'],'depositRequired'=>(float)$i['deposit_required'],'status'=>$i['status'],'date'=>substr($i['issued_at'],0,10),'itemDescription'=>$i['seats'].' traveller(s) · '.$i['package_label']];
    $gallery=$db->query("SELECT id,media_type,media_url,caption FROM gallery_items ORDER BY position,id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $social=json_decode((string)($db->query("SELECT setting_value FROM site_settings WHERE setting_key='social_links'")->fetchColumn() ?: '{}'),true) ?: [];
    $reviews=$db->query("SELECT id,trip_id,guest_name,rating,body,status FROM reviews ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $mappedReviews=array_map(fn($r)=>['id'=>(string)$r['id'],'trip'=>$r['trip_id'],'name'=>$r['guest_name'],'rating'=>(int)$r['rating'],'body'=>$r['body'],'status'=>$r['status']],$reviews);
    return ['trips'=>array_map('trip_row',$tripRows),'bookings'=>$mappedBookings,'invoices'=>$mappedInvoices,'gallery'=>array_map(fn($g)=>['id'=>(string)$g['id'],'type'=>$g['media_type'],'src'=>$g['media_url'],'caption'=>$g['caption']],$gallery),'socialLinks'=>$social,'reviews'=>$mappedReviews,'promotions'=>[]];
}

$configFile=__DIR__.'/nyathi-config.php';
if (!is_file($configFile)) respond(503,['ok'=>false,'error'=>'The secure Nyathi Tours backend is not configured on this host yet.']);
$config=require $configFile;
try {
    $db=new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4',$config['db_user'],$config['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
} catch(Throwable $e) { error_log('Nyathi database connection error: '.$e->getMessage()); respond(503,['ok'=>false,'error'=>'The booking system is temporarily unavailable. Please try again later.']); }
$secure=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off');
ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
session_start();
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
$action=(string)($_GET['action'] ?? $_POST['action'] ?? '');
$data=$method==='POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')!==false ? input_json() : $_POST;

try {
    if ($method==='GET' && $action==='public') {
        $gallery=$db->query("SELECT id,media_type,media_url,caption FROM gallery_items WHERE visibility='published' ORDER BY position,id DESC")->fetchAll();
        $social=json_decode((string)($db->query("SELECT setting_value FROM site_settings WHERE setting_key='social_links'")->fetchColumn() ?: '{}'),true) ?: [];
        $reviews=$db->query("SELECT id,trip_id,guest_name,rating,body FROM reviews WHERE status='approved' ORDER BY created_at DESC LIMIT 30")->fetchAll();
        respond(200,['ok'=>true,'trips'=>public_trips($db),'gallery'=>array_map(fn($g)=>['id'=>(string)$g['id'],'type'=>$g['media_type'],'src'=>$g['media_url'],'caption'=>$g['caption']],$gallery),'socialLinks'=>$social,'reviews'=>array_map(fn($r)=>['id'=>(string)$r['id'],'trip'=>$r['trip_id'],'name'=>$r['guest_name'],'rating'=>(int)$r['rating'],'body'=>$r['body'],'status'=>'approved'],$reviews)]);
    }
    if ($method==='POST' && $action==='initialize') {
        $host=strtolower((string)($_SERVER['HTTP_HOST']??''));if(!$secure&&!preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/',$host))respond(426,['ok'=>false,'error'=>'Run account setup only over HTTPS.']);
        $stored=(string)($config['installer_key'] ?? '');
        if (strlen($stored)<40 || empty($data['installer_key']) || !hash_equals($stored,(string)$data['installer_key'])) respond(403,['ok'=>false,'error'=>'Installer key is not valid.']);
        if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()!==0) respond(409,['ok'=>false,'error'=>'Accounts have already been initialized.']);
        $accounts=[['owner','Owner',filter_var($data['owner_email']??'',FILTER_VALIDATE_EMAIL),$data['owner_password']??''],['cto','Website administrator',filter_var($data['cto_email']??'',FILTER_VALIDATE_EMAIL),$data['cto_password']??'']];
        foreach($accounts as $a) if(!$a[2] || strlen((string)$a[3])<14) respond(422,['ok'=>false,'error'=>'Enter two valid email addresses and passwords of at least 14 characters.']);
        if(strtolower($accounts[0][2])===strtolower($accounts[1][2])) respond(422,['ok'=>false,'error'=>'Owner and CTO need separate email addresses.']);
        $db->beginTransaction();$q=$db->prepare('INSERT INTO users (email,password_hash,role,display_name) VALUES (?,?,?,?)');
        foreach($accounts as $a)$q->execute([strtolower($a[2]),password_hash((string)$a[3],PASSWORD_DEFAULT),$a[0],$a[1]]);
        $db->commit();respond(201,['ok'=>true,'message'=>'The two allowed accounts were created. Remove the installer key from server config now.']);
    }
    if ($method==='POST' && $action==='login') {
        $host=strtolower((string)($_SERVER['HTTP_HOST']??''));if(!$secure&&!preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/',$host))respond(426,['ok'=>false,'error'=>'Owner sign-in requires HTTPS.']);
        $email=strtolower(trim((string)($data['email']??'')));$password=(string)($data['password']??'');$role=(string)($data['role']??'');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)>256||!in_array($role,['owner','cto'],true))respond(422,['ok'=>false,'error'=>'Enter your account email, password and access type.']);
        $key=hash('sha256',$email.'|'.($_SERVER['REMOTE_ADDR']??''));$q=$db->prepare('SELECT attempts,last_attempt_at FROM login_attempts WHERE attempt_key=?');$q->execute([$key]);$attempt=$q->fetch();
        if($attempt && (int)$attempt['attempts']>=8 && strtotime($attempt['last_attempt_at'])>time()-900)respond(429,['ok'=>false,'error'=>'Too many sign-in attempts. Try again in 15 minutes.']);
        $q=$db->prepare('SELECT id,email,password_hash,role,display_name FROM users WHERE email=? AND role=? AND active=1');$q->execute([$email,$role]);$u=$q->fetch();
        if(!$u || !password_verify($password,$u['password_hash'])){$db->prepare('INSERT INTO login_attempts(attempt_key,attempts,last_attempt_at) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(last_attempt_at<NOW()-INTERVAL 15 MINUTE,1,attempts+1),last_attempt_at=NOW()')->execute([$key]);respond(401,['ok'=>false,'error'=>'Those sign-in details were not recognized.']);}
        $db->prepare('DELETE FROM login_attempts WHERE attempt_key=?')->execute([$key]);$db->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$u['id']]);session_regenerate_id(true);$_SESSION['user_id']=(int)$u['id'];$_SESSION['role']=$u['role'];$_SESSION['csrf']=bin2hex(random_bytes(32));
        respond(200,['ok'=>true,'csrf'=>$_SESSION['csrf'],'user'=>['email'=>$u['email'],'role'=>$u['role'],'name'=>$u['display_name']]]);
    }
    if ($method==='POST' && $action==='logout') { require_user($db);$_SESSION=[];session_destroy();setcookie(session_name(),'',['expires'=>time()-42000,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);respond(200,['ok'=>true]); }
    if ($method==='GET' && $action==='dashboard') { require_user($db,false);respond(200,['ok'=>true,'data'=>load_dashboard($db)]); }
    if ($method==='POST' && $action==='booking.create') {
        $name=trim((string)($data['name']??''));$phone=trim((string)($data['phone']??''));$date=date_or_null($data['date']??null);$seats=filter_var($data['seats']??null,FILTER_VALIDATE_INT);$tripId=(string)($data['trip']??'');
        if($name===''||strlen($name)>120||$phone===''||strlen($phone)>40||!$date||$date<date('Y-m-d')||!$seats||$seats<1||$seats>20||$tripId==='')respond(422,['ok'=>false,'error'=>'Complete the required booking details.']);
        $db->beginTransaction();$q=$db->prepare("SELECT * FROM trips WHERE id=? AND visibility='published' FOR UPDATE");$q->execute([$tripId]);$trip=$q->fetch();if(!$trip){$db->rollBack();respond(404,['ok'=>false,'error'=>'That trip is no longer available.']);}
        if($trip['start_date'] && $date!==$trip['start_date']){$db->rollBack();respond(422,['ok'=>false,'error'=>'Please choose the published departure date shown for this trip.']);}
        if($trip['capacity']!==null){$q=$db->prepare("SELECT COALESCE(SUM(seats),0) FROM bookings WHERE trip_id=? AND departure_date=? AND status='accepted'");$q->execute([$tripId,$date]);$remaining=max(0,(int)$trip['capacity']-(int)$q->fetchColumn());if($seats>$remaining){$db->rollBack();respond(409,['ok'=>false,'error'=>'There are not enough seats left for that departure.']);}}
        $opts=json_decode($trip['package_options']??'[]',true)?:[];$idx=filter_var($data['package_index']??0,FILTER_VALIDATE_INT);$option=$opts[$idx]??[];$price=(float)($option['price']??$trip['price']);$deposit=(float)($option['deposit']??0);$request='NT-R-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(3)));
        $q=$db->prepare("INSERT INTO bookings(request_number,trip_id,package_label,requested_price,requested_deposit,departure_date,seats,client_name,phone,email,pickup,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");$q->execute([$request,$tripId,substr((string)($data['package_label']??$option['label']??''),0,180),$price,$deposit,$date,$seats,$name,$phone,filter_var($data['email']??'',FILTER_VALIDATE_EMAIL)?:null,substr(trim((string)($data['pickup']??'')),0,180),substr(trim((string)($data['notes']??'')),0,1000)]);$id=(int)$db->lastInsertId();$db->commit();respond(201,['ok'=>true,'booking'=>['id'=>(string)$id,'requestNumber'=>$request,'status'=>'pending']]);
    }
    if ($method==='POST' && $action==='review.submit') {$trip=(string)($data['trip']??'');$name=substr(trim((string)($data['name']??'')),0,90);$body=substr(trim((string)($data['body']??'')),0,1000);$rating=filter_var($data['rating']??null,FILTER_VALIDATE_INT);if($name===''||$body===''||!$rating||$rating<1||$rating>5)respond(422,['ok'=>false,'error'=>'Complete the review fields.']);$q=$db->prepare("INSERT INTO reviews(trip_id,guest_name,rating,body) VALUES(?,?,?,?)");$q->execute([$trip?:null,$name,$rating,$body]);respond(201,['ok'=>true]);}
    $user=require_user($db,true);
    if ($method==='POST' && $action==='trip.save') {
        $id=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($data['id']??''));$name=trim((string)($data['name']??''));$summary=trim((string)($data['summary']??''));$duration=trim((string)($data['duration']??''));$price=filter_var($data['price']??null,FILTER_VALIDATE_FLOAT);$capacity=($data['capacity']??'')===''?null:filter_var($data['capacity'],FILTER_VALIDATE_INT);$start=date_or_null($data['startDate']??null);$end=date_or_null($data['endDate']??null);$vis=($data['visible']??false)?'published':'hidden';
        if($id===''||$name===''||$summary===''||$duration===''||$price===false||$price<0||($capacity!==null&&($capacity===false||$capacity<1||$capacity>500))||(($data['startDate']??'')!==''&&!$start)||(($data['endDate']??'')!==''&&!$end))respond(422,['ok'=>false,'error'=>'Check the trip details and seat capacity.']);
        $q=$db->prepare("INSERT INTO trips(id,name,tag,summary,duration,price,price_text,start_date,end_date,capacity,pickup,detail,image_url,package_options,visibility) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),tag=VALUES(tag),summary=VALUES(summary),duration=VALUES(duration),price=VALUES(price),price_text=VALUES(price_text),start_date=VALUES(start_date),end_date=VALUES(end_date),capacity=VALUES(capacity),pickup=VALUES(pickup),detail=VALUES(detail),image_url=VALUES(image_url),package_options=VALUES(package_options),visibility=VALUES(visibility)");
        $q->execute([$id,$name,substr((string)($data['tag']??''),0,100),$summary,$duration,$price,substr((string)($data['priceText']??''),0,120),$start,$end,$capacity,substr((string)($data['pickup']??''),0,500),$data['detail']??'',substr((string)($data['image']??''),0,500),json_encode($data['options']??[]),$vis]);respond(200,['ok'=>true]);
    }
    if ($method==='POST' && $action==='booking.accept') {
        $id=filter_var($data['id']??null,FILTER_VALIDATE_INT);$price=filter_var($data['price']??null,FILTER_VALIDATE_FLOAT);if(!$id||$price===false||$price<=0)respond(422,['ok'=>false,'error'=>'A valid booking and confirmed per-person price are required.']);
        $db->beginTransaction();$q=$db->prepare('SELECT b.*,t.capacity FROM bookings b JOIN trips t ON t.id=b.trip_id WHERE b.id=? FOR UPDATE');$q->execute([$id]);$b=$q->fetch();if(!$b||$b['status']!=='pending'){$db->rollBack();respond(409,['ok'=>false,'error'=>'This request is no longer pending.']);}
        if($b['capacity']!==null){$q=$db->prepare("SELECT COALESCE(SUM(seats),0) FROM bookings WHERE trip_id=? AND departure_date=? AND status='accepted'");$q->execute([$b['trip_id'],$b['departure_date']]);if((int)$b['seats']>(int)$b['capacity']-(int)$q->fetchColumn()){$db->rollBack();respond(409,['ok'=>false,'error'=>'There are not enough seats remaining to accept this request.']);}}
        $number='NT-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(4)));$total=$price*(int)$b['seats'];$q=$db->prepare("UPDATE bookings SET status='accepted',accepted_price=?,accepted_at=NOW() WHERE id=?");$q->execute([$price,$id]);$q=$db->prepare("INSERT INTO invoices(invoice_number,booking_id,total,deposit_required) VALUES(?,?,?,?)");$q->execute([$number,$id,$total,$b['requested_deposit']]);$invoiceId=(int)$db->lastInsertId();
        if($b['email']){$q=$db->prepare("INSERT INTO reminder_jobs(booking_id,reminder_type,scheduled_at) VALUES(?,'booking_accepted',NOW())");$q->execute([$id]);$tripDate=new DateTime($b['departure_date']);$tripDate->modify('-7 days');if($tripDate>new DateTime('now')){$q=$db->prepare("INSERT INTO reminder_jobs(booking_id,reminder_type,scheduled_at) VALUES(?,'trip_soon',?)");$q->execute([$id,$tripDate->format('Y-m-d H:i:s')]);}}
        $db->commit();respond(200,['ok'=>true,'invoice'=>['id'=>(string)$invoiceId,'number'=>$number,'total'=>$total]]);
    }
    if ($method==='POST' && $action==='booking.decline') {$id=filter_var($data['id']??null,FILTER_VALIDATE_INT);$q=$db->prepare("UPDATE bookings SET status='declined' WHERE id=? AND status='pending'");$q->execute([$id]);if(!$q->rowCount())respond(404,['ok'=>false,'error'=>'Pending booking not found.']);respond(200,['ok'=>true]);}
    if ($method==='POST' && $action==='media.upload') {
        $file=$_FILES['media']??null;if(!$file||$file['error']!==UPLOAD_ERR_OK)respond(422,['ok'=>false,'error'=>'Choose a photo or video to upload.']);if($file['size']>(int)($config['max_upload_bytes']??18874368))respond(413,['ok'=>false,'error'=>'That file is too large.']);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov'];if(!isset($allowed[$mime]))respond(415,['ok'=>false,'error'=>'Use a JPG, PNG, WebP, MP4, WebM or MOV file.']);
        $dir=$config['uploads_dir'];if(!is_dir($dir)&&!mkdir($dir,0750,true))respond(500,['ok'=>false,'error'=>'Media storage is not available.']);$name='media-'.bin2hex(random_bytes(16)).'.'.$allowed[$mime];if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$name))respond(500,['ok'=>false,'error'=>'Could not store this media file.']);respond(201,['ok'=>true,'url'=>'/'.$name,'type'=>str_starts_with($mime,'image/')?'image':'video']);
    }
    if ($method==='POST' && $action==='gallery.save') {$url=(string)($data['url']??'');if(!preg_match('#^/media-[a-f0-9]{32}\.(jpg|png|webp|mp4|webm|mov)$#',$url))respond(422,['ok'=>false,'error'=>'Upload the media before saving the gallery post.']);$type=pathinfo($url,PATHINFO_EXTENSION);$kind=in_array($type,['mp4','webm','mov'],true)?'video':'image';$q=$db->prepare('INSERT INTO gallery_items(media_type,media_url,caption,position) VALUES(?,?,?,0)');$q->execute([$kind,$url,substr(trim((string)($data['caption']??'')),0,500)]);respond(201,['ok'=>true,'id'=>(string)$db->lastInsertId()]);}
    if ($method==='POST' && $action==='gallery.delete') {$id=filter_var($data['id']??null,FILTER_VALIDATE_INT);$q=$db->prepare('DELETE FROM gallery_items WHERE id=?');$q->execute([$id]);respond(200,['ok'=>true]);}
    if ($method==='POST' && $action==='social.save') {$links=['facebook'=>(string)($data['facebook']??''),'instagram'=>(string)($data['instagram']??'')];foreach($links as $url)if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true)))respond(422,['ok'=>false,'error'=>'Enter valid Facebook and Instagram URLs.']);$q=$db->prepare("INSERT INTO site_settings(setting_key,setting_value) VALUES('social_links',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");$q->execute([json_encode($links)]);respond(200,['ok'=>true]);}
    if ($method==='POST' && $action==='review.moderate') {$id=filter_var($data['id']??null,FILTER_VALIDATE_INT);$status=in_array($data['status']??'', ['approved','removed'],true)?$data['status']:'';if(!$id||!$status)respond(422,['ok'=>false,'error'=>'Choose an allowed review action.']);$q=$db->prepare('UPDATE reviews SET status=? WHERE id=?');$q->execute([$status,$id]);respond(200,['ok'=>true]);}
    if ($method==='GET' && $action==='dashboard') { /* already handled above for authenticated reads */ }
    respond(404,['ok'=>false,'error'=>'Unknown API action.']);
} catch(Throwable $e) { if($db->inTransaction())$db->rollBack();error_log('Nyathi API error: '.$e->getMessage());respond(500,['ok'=>false,'error'=>'The request could not be completed.']); }
