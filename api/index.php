<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$frontendOrigin = getenv('FRONTEND_ORIGIN') ?: 'http://localhost:5173';
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($requestOrigin !== '' && $requestOrigin !== $frontendOrigin) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Origin is not allowed.']);
    exit;
}
if ($requestOrigin !== '') header('Access-Control-Allow-Origin: ' . $frontendOrigin);
header('Vary: Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

try {
    $pdo = db();
    $recordActivity = static function (int $workspaceId, string $action, string $entityType, ?int $entityId = null) use ($pdo): void {
        $stmt = $pdo->prepare('INSERT INTO activity_log (workspace_id,user_id,action,entity_type,entity_id) VALUES (?,?,?,?,?)');
        $stmt->execute([$workspaceId, (int)$_SESSION['user_id'], $action, $entityType, $entityId]);
    };
    $method = $_SERVER['REQUEST_METHOD'];
    $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $parts = explode('/', $path);
    $resource = $_GET['resource'] ?? end($parts);
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $payload = in_array($method, ['POST', 'PATCH'], true) ? body() : [];

    if ($resource === 'auth' && $method === 'GET') {
        if (empty($_SESSION['user_id'])) respond(['user' => null]);
        $stmt=$pdo->prepare('SELECT id,name,email FROM users WHERE id=?');$stmt->execute([(int)$_SESSION['user_id']]);
        $user=$stmt->fetch();if(!$user){session_destroy();respond(['user'=>null]);}
        respond(['user'=>$user]);
    }
    if ($resource === 'auth' && $method === 'POST') {
        $action=$payload['action']??'login';
        if($action==='logout'){$_SESSION=[];if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',(time()-42000),$params['path'],$params['domain'],$params['secure'],$params['httponly']);}session_destroy();respond(['ok'=>true]);}
        requireFields($payload,['email','password']);$email=strtolower(trim($payload['email']));
        if($action==='register'){
            requireFields($payload,['name']);
            if(strlen($payload['password'])<10)respond(['error'=>'Use a password with at least 10 characters.'],422);
            $stmt=$pdo->prepare('SELECT u.id,u.password_hash FROM users u JOIN workspace_members wm ON wm.user_id=u.id WHERE u.email=? LIMIT 1');$stmt->execute([$email]);$account=$stmt->fetch();
            if(!$account)respond(['error'=>'This email has not been invited to a workspace. Ask a workspace owner to add you first.'],403);
            if(!empty($account['password_hash']))respond(['error'=>'This account is already active. Sign in instead.'],409);
            $stmt=$pdo->prepare('UPDATE users SET name=?,password_hash=? WHERE id=?');$stmt->execute([trim($payload['name']),password_hash($payload['password'],PASSWORD_DEFAULT),(int)$account['id']]);
            session_regenerate_id(true);$_SESSION['user_id']=(int)$account['id'];
        }elseif($action==='login'){
            $window=$_SESSION['login_window']??time();$attempts=$_SESSION['login_attempts']??0;
            if(time()-(int)$window>600){$window=time();$attempts=0;}
            if($attempts>=8)respond(['error'=>'Too many attempts. Wait a few minutes and try again.'],429);
            $stmt=$pdo->prepare('SELECT id,password_hash FROM users WHERE email=?');$stmt->execute([$email]);$account=$stmt->fetch();
            if(!$account||empty($account['password_hash'])||!password_verify($payload['password'],$account['password_hash'])){$_SESSION['login_window']=$window;$_SESSION['login_attempts']=$attempts+1;respond(['error'=>'Email or password is incorrect.'],401);}
            session_regenerate_id(true);$_SESSION['user_id']=(int)$account['id'];unset($_SESSION['login_attempts'],$_SESSION['login_window']);
        }else{respond(['error'=>'Unknown authentication action.'],422);}
        $stmt=$pdo->prepare('SELECT id,name,email FROM users WHERE id=?');$stmt->execute([(int)$_SESSION['user_id']]);respond(['user'=>$stmt->fetch()]);
    }
    if ($resource !== 'health' && empty($_SESSION['user_id'])) respond(['error'=>'Please sign in to continue.'],401);
    $assertWorkspace = static function (int $workspaceId, bool $write = false) use ($pdo): void {
        if($workspaceId<1)respond(['error'=>'workspace_id is required'],422);
        $stmt=$pdo->prepare('SELECT role FROM workspace_members WHERE workspace_id=? AND user_id=?');$stmt->execute([$workspaceId,(int)$_SESSION['user_id']]);$role=$stmt->fetchColumn();
        if(!$role)respond(['error'=>'You do not have access to this workspace.'],403);
        if($write&&!in_array($role,['Owner','Admin','Member'],true))respond(['error'=>'Your workspace role is read-only.'],403);
    };
    $assertManager = static function (int $workspaceId) use ($pdo): void {
        $stmt=$pdo->prepare('SELECT role FROM workspace_members WHERE workspace_id=? AND user_id=?');$stmt->execute([$workspaceId,(int)$_SESSION['user_id']]);$role=$stmt->fetchColumn();
        if(!in_array($role,['Owner','Admin'],true))respond(['error'=>'Only workspace owners and admins can manage members.'],403);
    };

    if ($method === 'GET' && $resource === 'health') respond(['ok' => true, 'service' => 'Stride API']);
    if ($method === 'GET' && $resource === 'workspaces') {
        $stmt=$pdo->prepare('SELECT w.*, (SELECT COUNT(*) FROM workspace_members wm WHERE wm.workspace_id=w.id) AS member_count, (SELECT COUNT(*) FROM projects p WHERE p.workspace_id=w.id) AS project_count FROM workspaces w JOIN workspace_members mine ON mine.workspace_id=w.id WHERE mine.user_id=? ORDER BY w.created_at DESC');$stmt->execute([(int)$_SESSION['user_id']]);respond($stmt->fetchAll());
    }
    if ($resource === 'workspaces' && $method === 'POST') {
        requireFields($payload, ['name']);
        $owner=$pdo->prepare('SELECT id,name FROM users WHERE id=?');$owner->execute([(int)$_SESSION['user_id']]);$currentUser=$owner->fetch();$ownerId=(int)$currentUser['id'];$ownerName=$currentUser['name'];
        $stmt = $pdo->prepare('INSERT INTO workspaces (name, owner_name) VALUES (?, ?)');
        $stmt->execute([trim($payload['name']), $ownerName]);$workspaceId=(int)$pdo->lastInsertId();
        $member=$pdo->prepare("INSERT INTO workspace_members (workspace_id,user_id,role) VALUES (?,?,'Owner')");$member->execute([$workspaceId,(int)$ownerId]);
        respond(['id' => $workspaceId, 'name' => trim($payload['name'])], 201);
    }
    if ($resource === 'workspaces' && $method === 'PATCH') {
        requireFields($payload,['id','name']);$workspaceId=(int)$payload['id'];$assertManager($workspaceId);
        $stmt=$pdo->prepare('UPDATE workspaces SET name=? WHERE id=?');$stmt->execute([trim($payload['name']),$workspaceId]);
        respond(['updated'=>$stmt->rowCount()]);
    }
    if ($resource === 'workspaces' && $method === 'DELETE') {
        $workspaceId=(int)($_GET['id']??0);$assertManager($workspaceId);
        $stmt=$pdo->prepare('SELECT role FROM workspace_members WHERE workspace_id=? AND user_id=?');$stmt->execute([$workspaceId,(int)$_SESSION['user_id']]);
        if($stmt->fetchColumn()!=='Owner')respond(['error'=>'Only the workspace owner can delete this workspace.'],403);
        $delete=$pdo->prepare('DELETE FROM workspaces WHERE id=?');$delete->execute([$workspaceId]);respond(['deleted'=>$delete->rowCount()]);
    }
    if ($method === 'GET' && $resource === 'projects') {
        $assertWorkspace((int)($_GET['workspace_id']??0));
        $sql = 'SELECT p.*, u.name AS lead_name, COUNT(DISTINCT t.id) AS task_count, COUNT(DISTINCT CASE WHEN t.status="Done" THEN t.id END) AS completed_count FROM projects p LEFT JOIN users u ON u.id=p.lead_id LEFT JOIN tasks t ON t.project_id=p.id';
        $params = [];
        if (!empty($_GET['workspace_id'])) { $sql .= ' WHERE p.workspace_id=?'; $params[] = (int)$_GET['workspace_id']; }
        $sql .= ' GROUP BY p.id ORDER BY p.created_at DESC';
        $stmt = $pdo->prepare($sql); $stmt->execute($params); respond($stmt->fetchAll());
    }
    if ($resource === 'projects' && $method === 'POST') {
        requireFields($payload, ['workspace_id', 'name']);
        $stmt = $pdo->prepare('INSERT INTO projects (workspace_id,name,project_key,description,category,color,status,due_date,lead_id) VALUES (?,?,?,?,?,?,?,?,?)');
        $key = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $payload['name']), 0, 4));
        $workspaceId=(int)$payload['workspace_id'];
        $assertWorkspace($workspaceId,true);
        $stmt->execute([$workspaceId, trim($payload['name']), $key ?: 'PROJ', $payload['description'] ?? '', $payload['category'] ?? 'General', $payload['color'] ?? '#8976f5', $payload['status'] ?? 'Planning', ($payload['due_date'] ?? '') ?: null, ($payload['lead_id'] ?? '') ?: null]);
        $projectId=(int)$pdo->lastInsertId();$recordActivity($workspaceId,'created','project',$projectId);
        respond(['id' => $projectId], 201);
    }
    if ($resource === 'projects' && $method === 'PATCH' && $id) {
        $allowed = ['name','description','category','color','status','due_date','lead_id','is_starred']; $sets=[]; $values=[];
        foreach ($allowed as $key) if (array_key_exists($key, $payload)) { $sets[]="{$key}=?"; $values[]=$payload[$key] === '' ? null : $payload[$key]; }
        if (!$sets) respond(['error'=>'No fields to update'], 422);
        $scope=$pdo->prepare('SELECT workspace_id FROM projects WHERE id=?');$scope->execute([$id]);$workspaceId=(int)$scope->fetchColumn();$assertWorkspace($workspaceId,true);
        $values[]=$id; $stmt=$pdo->prepare('UPDATE projects SET '.implode(',', $sets).' WHERE id=?'); $stmt->execute($values);$recordActivity($workspaceId,'updated','project',$id);respond(['updated'=>$stmt->rowCount()]);
    }
    if ($resource === 'projects' && $method === 'DELETE' && $id) { $scope=$pdo->prepare('SELECT workspace_id FROM projects WHERE id=?');$scope->execute([$id]);$workspaceId=(int)$scope->fetchColumn();$assertWorkspace($workspaceId,true);$stmt=$pdo->prepare('DELETE FROM projects WHERE id=?'); $stmt->execute([$id]);$recordActivity($workspaceId,'deleted','project',$id);respond(['deleted'=>$stmt->rowCount()]); }
    if ($method === 'GET' && $resource === 'tasks') {
        if(!empty($_GET['workspace_id']))$assertWorkspace((int)$_GET['workspace_id']);
        elseif(!empty($_GET['project_id'])){$scope=$pdo->prepare('SELECT workspace_id FROM projects WHERE id=?');$scope->execute([(int)$_GET['project_id']]);$assertWorkspace((int)$scope->fetchColumn());}
        else respond(['error'=>'workspace_id or project_id is required'],422);
        $sql='SELECT t.*, p.name AS project_name, p.color AS project_color, u.name AS assignee_name FROM tasks t JOIN projects p ON p.id=t.project_id LEFT JOIN users u ON u.id=t.assignee_id'; $params=[];
        if (!empty($_GET['workspace_id'])) { $sql.=' WHERE p.workspace_id=?'; $params[]=(int)$_GET['workspace_id']; }
        elseif (!empty($_GET['project_id'])) { $sql.=' WHERE t.project_id=?'; $params[]=(int)$_GET['project_id']; }
        if (!empty($_GET['assignee_id'])) { $sql.=(strpos($sql,' WHERE ')!==false?' AND':' WHERE'); $sql.=' t.assignee_id=?'; $params[]=(int)$_GET['assignee_id']; }
        $sql.=' ORDER BY t.due_date IS NULL, t.due_date, t.created_at DESC'; $stmt=$pdo->prepare($sql); $stmt->execute($params); respond($stmt->fetchAll());
    }
    if ($resource === 'tasks' && $method === 'POST') {
        requireFields($payload,['project_id','title']);
        $stmt=$pdo->prepare('INSERT INTO tasks (project_id,title,description,assignee_id,due_date,priority,status) VALUES (?,?,?,?,?,?,?)');
        $projectId=(int)$payload['project_id'];
        $scope=$pdo->prepare('SELECT workspace_id FROM projects WHERE id=?');$scope->execute([$projectId]);$workspaceId=(int)$scope->fetchColumn();$assertWorkspace($workspaceId,true);
        $stmt->execute([$projectId,trim($payload['title']),$payload['description']??'',($payload['assignee_id']??'')?:null,($payload['due_date']??'')?:null,$payload['priority']??'Medium',$payload['status']??'To do']);
        $taskId=(int)$pdo->lastInsertId();$recordActivity($workspaceId,'created','task',$taskId);
        respond(['id'=>$taskId],201);
    }
    if ($resource === 'tasks' && $method === 'PATCH' && $id) {
        $allowed=['title','description','assignee_id','due_date','priority','status']; $sets=[];$values=[];
        foreach($allowed as $key)if(array_key_exists($key,$payload)){$sets[]="{$key}=?";$values[]=$payload[$key]===''?null:$payload[$key];}
        if(!$sets)respond(['error'=>'No fields to update'],422);$scope=$pdo->prepare('SELECT p.workspace_id FROM tasks t JOIN projects p ON p.id=t.project_id WHERE t.id=?');$scope->execute([$id]);$workspaceId=(int)$scope->fetchColumn();$assertWorkspace($workspaceId,true);$values[]=$id;$stmt=$pdo->prepare('UPDATE tasks SET '.implode(',',$sets).' WHERE id=?');$stmt->execute($values);$recordActivity($workspaceId,'updated','task',$id);respond(['updated'=>$stmt->rowCount()]);
    }
    if ($resource === 'tasks' && $method === 'DELETE' && $id) { $scope=$pdo->prepare('SELECT p.workspace_id FROM tasks t JOIN projects p ON p.id=t.project_id WHERE t.id=?');$scope->execute([$id]);$workspaceId=(int)$scope->fetchColumn();$assertWorkspace($workspaceId,true);$stmt=$pdo->prepare('DELETE FROM tasks WHERE id=?');$stmt->execute([$id]);if($stmt->rowCount())$recordActivity($workspaceId,'deleted','task',$id);respond(['deleted'=>$stmt->rowCount()]); }
    if ($method === 'GET' && $resource === 'members') {
        $workspaceId=(int)($_GET['workspace_id']??0); if(!$workspaceId)respond(['error'=>'workspace_id is required'],422);
        $assertWorkspace($workspaceId);
        $stmt=$pdo->prepare('SELECT u.id,u.name,u.email,wm.role,wm.joined_at,(SELECT COUNT(*) FROM tasks t JOIN projects p ON p.id=t.project_id WHERE t.assignee_id=u.id AND t.status<>"Done" AND p.workspace_id=wm.workspace_id) AS open_tasks FROM workspace_members wm JOIN users u ON u.id=wm.user_id WHERE wm.workspace_id=? ORDER BY u.name');$stmt->execute([$workspaceId]);respond($stmt->fetchAll());
    }
    if ($method === 'GET' && $resource === 'activity') {
        $workspaceId=(int)($_GET['workspace_id']??0);if(!$workspaceId)respond(['error'=>'workspace_id is required'],422);
        $assertWorkspace($workspaceId);
        $stmt=$pdo->prepare('SELECT a.id,a.action,a.entity_type,a.entity_id,a.details,a.created_at,u.name AS user_name FROM activity_log a LEFT JOIN users u ON u.id=a.user_id WHERE a.workspace_id=? ORDER BY a.created_at DESC LIMIT 30');$stmt->execute([$workspaceId]);respond($stmt->fetchAll());
    }
    if ($resource === 'members' && $method === 'POST') {
        requireFields($payload,['workspace_id','email']);$email=strtolower(trim($payload['email']));
        $workspaceId=(int)$payload['workspace_id'];$assertManager($workspaceId);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))respond(['error'=>'Enter a valid email address'],422);
        $stmt=$pdo->prepare('SELECT id FROM users WHERE email=?');$stmt->execute([$email]);$userId=$stmt->fetchColumn();
        if(!$userId){$name=trim($payload['name']??explode('@',$email)[0]);$stmt=$pdo->prepare('INSERT INTO users (name,email) VALUES (?,?)');$stmt->execute([$name,$email]);$userId=(int)$pdo->lastInsertId();}
        $role=$payload['role']??'Member';if(!in_array($role,['Admin','Member','Viewer'],true))respond(['error'=>'Choose a valid workspace role.'],422);
        $stmt=$pdo->prepare('INSERT INTO workspace_members (workspace_id,user_id,role) VALUES (?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role)');$stmt->execute([$workspaceId,$userId,$role]);$recordActivity($workspaceId,'added a member to','workspace',(int)$userId);respond(['user_id'=>(int)$userId],201);
    }
    if ($resource === 'members' && $method === 'PATCH') {
        requireFields($payload,['workspace_id','user_id','role']);$workspaceId=(int)$payload['workspace_id'];$assertManager($workspaceId);if(!in_array($payload['role'],['Owner','Admin','Member','Viewer'],true))respond(['error'=>'Choose a valid workspace role.'],422);$actor=$pdo->prepare('SELECT role FROM workspace_members WHERE workspace_id=? AND user_id=?');$actor->execute([$workspaceId,(int)$_SESSION['user_id']]);$actorRole=$actor->fetchColumn();if($payload['role']==='Owner'&&$actorRole!=='Owner')respond(['error'=>'Only an owner can transfer workspace ownership.'],403);if((int)$payload['user_id']===(int)$_SESSION['user_id']&&$payload['role']!=='Owner')respond(['error'=>'You cannot change your own workspace role.'],403);$stmt=$pdo->prepare('UPDATE workspace_members SET role=? WHERE workspace_id=? AND user_id=? AND (role<>"Owner" OR ?="Owner")');$stmt->execute([$payload['role'],$workspaceId,(int)$payload['user_id'],$payload['role']]);$recordActivity($workspaceId,'changed a member role in','workspace',(int)$payload['user_id']);respond(['updated'=>$stmt->rowCount()]);
    }
    if ($resource === 'members' && $method === 'DELETE') {
        requireFields($payload,['workspace_id','user_id']);$workspaceId=(int)$payload['workspace_id'];$assertManager($workspaceId);if((int)$payload['user_id']===(int)$_SESSION['user_id'])respond(['error'=>'You cannot remove yourself from the workspace.'],403);$stmt=$pdo->prepare('DELETE FROM workspace_members WHERE workspace_id=? AND user_id=? AND role<>"Owner"');$stmt->execute([$workspaceId,(int)$payload['user_id']]);if($stmt->rowCount())$recordActivity($workspaceId,'removed a member from','workspace',(int)$payload['user_id']);respond(['deleted'=>$stmt->rowCount()]);
    }
    if ($method === 'GET' && $resource === 'analytics') {
        $workspaceId=(int)($_GET['workspace_id']??0);if(!$workspaceId)respond(['error'=>'workspace_id is required'],422);
        $assertWorkspace($workspaceId);
        $days=(int)($_GET['days']??30);$dateClause=$days>0?' AND t.created_at>=DATE_SUB(CURRENT_DATE, INTERVAL '.max(1,min($days,3650)).' DAY)':'';
        $stmt=$pdo->prepare('SELECT COUNT(*) AS project_count, SUM(status="Completed") AS completed_projects FROM projects WHERE workspace_id=?');$stmt->execute([$workspaceId]);$summary=$stmt->fetch();
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM workspace_members WHERE workspace_id=?');$stmt->execute([$workspaceId]);$summary['team_size']=(int)$stmt->fetchColumn();
        $stmt=$pdo->prepare('SELECT COUNT(*) AS task_count,SUM(t.status="Done") AS completed_tasks FROM tasks t JOIN projects p ON p.id=t.project_id WHERE p.workspace_id=?'.$dateClause);$stmt->execute([$workspaceId]);$tasks=$stmt->fetch();
        $summary['task_count']=(int)$tasks['task_count'];$summary['completed_tasks']=(int)$tasks['completed_tasks'];$summary['completion_rate']=$summary['task_count']?round($summary['completed_tasks']/$summary['task_count']*100,1):0;
        $joinDate=$days>0?' AND t.created_at>=DATE_SUB(CURRENT_DATE, INTERVAL '.max(1,min($days,3650)).' DAY)':'';
        $stmt=$pdo->prepare('SELECT p.id,p.name,p.color,p.status,COUNT(t.id) AS task_count,SUM(t.status="Done") AS completed_tasks FROM projects p LEFT JOIN tasks t ON t.project_id=p.id'.$joinDate.' WHERE p.workspace_id=? GROUP BY p.id ORDER BY p.created_at DESC');$stmt->execute([$workspaceId]);$summary['projects']=$stmt->fetchAll();respond($summary);
    }
    respond(['error'=>'Endpoint not found'],404);
} catch (Throwable $e) {
    error_log($e->getMessage());
    respond(['error'=>'The server could not complete this request. Check the database connection and schema.'],500);
}
