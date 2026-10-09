<?php
declare(strict_types=1);
namespace Pulse;
require_once __DIR__.'/PanelValidation.php';
require_once __DIR__.'/ChipeiraGateway.php';
require_once __DIR__.'/SmsBilling.php';
require_once __DIR__.'/AdminApi.php';

final class PanelApi {
    private \PDO $db;
    private string $secret;
    private ?array $session = null;
    private string $token = '';
    private array $chipeira;
    private array $admins;
    public function __construct(\PDO $db, string $secret, array $chipeira = [], array $admins = []) {
        $this->db = $db; $this->secret = $secret;
        $this->chipeira = $chipeira;
        $this->admins = array_map('strval',$admins);
    }
    private function query(string $sql, array $params = []): \PDOStatement {
        $statement = $this->db->prepare($sql); $statement->execute($params); return $statement;
    }
    private function row(string $sql, array $params = []): ?array {
        $row = $this->query($sql, $params)->fetch(\PDO::FETCH_ASSOC); return $row ?: null;
    }
    private function rows(string $sql, array $params = []): array {
        return $this->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function authenticate(string $token): bool {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
        $this->session = $this->row("SELECT s.id session_id,s.user_id,s.company_id,u.name user_name,u.email,cu.role,c.name company_name FROM auth_sessions s JOIN users u ON u.id=s.user_id JOIN company_users cu ON cu.user_id=s.user_id AND cu.company_id=s.company_id JOIN companies c ON c.id=s.company_id WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>UTC_TIMESTAMP() AND u.status='active' AND u.deleted_at IS NULL AND cu.status='active' AND c.status IN ('pending','active') AND c.deleted_at IS NULL", [hash('sha256', $token)]);
        if (!$this->session) return false;
        $this->token = $token; return true;
    }
    public function csrfToken(): string { return hash_hmac('sha256', 'pulse-csrf:'.$this->token, $this->secret); }
    public function requireCsrf(string $token): void {
        if (!$this->session) throw new PanelError('unauthenticated', 'Entre na sua conta.', 401);
        if (!hash_equals($this->csrfToken(), $token)) throw new PanelError('csrf_failed', 'Atualize a sessão antes de continuar.', 403);
    }
    private function requireRole(array $roles): void {
        if (!$this->session || !in_array($this->session['role'], $roles, true)) throw new PanelError('forbidden', 'Seu acesso não permite esta operação.', 403);
    }
    private function user(): string { return (string)$this->session['user_id']; }
    private function company(): string { return (string)$this->session['company_id']; }
    public function sessionInfo(): array {
        if (!$this->session) return ['authenticated' => false];
        return ['authenticated'=>true,'is_admin'=>in_array($this->user(),$this->admins,true),'user'=>['id'=>(string)$this->session['user_id'],'name'=>$this->session['user_name'],'email'=>$this->session['email']], 'company'=>['id'=>$this->company(),'name'=>$this->session['company_name']], 'role'=>$this->session['role'],'csrf_token'=>$this->csrfToken(),'uploads_available'=>false];
    }
    public function login(array $data, string $ip): string {
        $email = PanelValidation::email($data, 'email');
        $password = $data['password'] ?? '';
        if (!is_string($password) || $password === '' || strlen($password)>1024) throw new PanelError('login_failed', 'E-mail ou senha inválidos.', 401);
        $key = hash_hmac('sha256', $ip.'|'.$email, $this->secret);
        $this->query('INSERT IGNORE INTO auth_login_attempts (attempt_key,attempts,window_started_at) VALUES (?,0,UTC_TIMESTAMP())', [$key]);
        $this->db->beginTransaction();
        try {
            $attempt = $this->row('SELECT attempts,window_started_at FROM auth_login_attempts WHERE attempt_key=? FOR UPDATE', [$key]);
            $fresh = strtotime($attempt['window_started_at'].' UTC') < time()-900;
            $count = $fresh ? 0 : (int)$attempt['attempts'];
            if ($count >= 10) throw new PanelError('rate_limited', 'Aguarde alguns minutos antes de tentar novamente.', 429);
            $this->query('UPDATE auth_login_attempts SET attempts=?,window_started_at=? WHERE attempt_key=?', [$count+1, $fresh ? gmdate('Y-m-d H:i:s') : $attempt['window_started_at'], $key]);
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
        $user = $this->row("SELECT id,password_hash FROM users WHERE email=? AND status='active' AND deleted_at IS NULL", [$email]);
        // Fixed dummy hash keeps nonexistent-account verification on the password path.
        $valid = password_verify($password, $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
        if (!$user || !$valid) throw new PanelError('login_failed', 'E-mail ou senha inválidos.', 401);
        $memberships = $this->rows("SELECT cu.company_id,c.name FROM company_users cu JOIN companies c ON c.id=cu.company_id WHERE cu.user_id=? AND cu.status='active' AND c.status IN ('pending','active') AND c.deleted_at IS NULL ORDER BY cu.company_id", [$user['id']]);
        $selected = $data['company_id'] ?? null;
        if ($selected !== null) $selected = PanelValidation::id($selected);
        if (!$memberships) throw new PanelError('login_failed', 'Nenhuma empresa disponível para esta conta.', 403);
        if (count($memberships)>1 && $selected === null) throw new PanelError('company_required', 'Informe a empresa para entrar.', 409);
        $company = null;
        foreach ($memberships as $membership) if ($selected === null || (string)$membership['company_id'] === $selected) { $company=$membership['company_id']; break; }
        if ($company === null) throw new PanelError('login_failed', 'Empresa indisponível para esta conta.', 403);
        $token = bin2hex(random_bytes(32));
        $this->query('INSERT INTO auth_sessions (user_id,company_id,token_hash,expires_at,last_seen_at) VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),UTC_TIMESTAMP())', [$user['id'],$company,hash('sha256',$token)]);
        $this->query('UPDATE users SET last_login_at=UTC_TIMESTAMP() WHERE id=?',[$user['id']]);
        $this->query('DELETE FROM auth_login_attempts WHERE attempt_key=?',[$key]);
        $this->authenticate($token); return $token;
    }
    public function handle(string $action, string $method, array $data): array {
        if ($action==='session') {
            if ($method!=='GET') throw new PanelError('method_not_allowed','Use GET.',405);
            return $this->sessionInfo();
        }
        if (!$this->session) throw new PanelError('unauthenticated','Entre na sua conta.',401);
        if ($action==='logout') {
            if ($method!=='POST') throw new PanelError('method_not_allowed','Use POST.',405);
            $this->query('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP() WHERE id=?',[$this->session['session_id']]);
            return ['logged_out'=>true];
        }
        if ($action==='chipeira') {
            if ($method==='POST') $this->requireRole(['owner','manager','operator']);
            $billing=new SmsBilling($this->db,new ChipeiraGateway($this->chipeira));
            if (($data['op']??'')==='quote' && $method==='POST') return $billing->estimate($this->company(),$data);
            if (($data['op']??'')==='create' && $method==='POST') return $billing->create($this->company(),$data);
            return (new ChipeiraGateway($this->chipeira))->handle($this->company(),$method,$data);
        }
        if ($action==='admin') {
            if (!in_array($this->user(),$this->admins,true)) throw new PanelError('forbidden','Acesso restrito à administração Pulse.',403);
            return (new AdminApi($this->db,$this->user(),$this->chipeira))->handle($method,$data);
        }
        if ($action==='sms_reconcile') {
            if ($method!=='POST') throw new PanelError('method_not_allowed','Use POST.',405);
            $this->requireRole(['owner','manager','operator']);
            return (new SmsBilling($this->db,new ChipeiraGateway($this->chipeira)))->reconcile($this->company());
        }
        if (in_array($action, ['billing_preferences','credit_requests','invoices','balance'], true)) {
            if (!in_array($method, ['GET','POST'], true)) throw new PanelError('method_not_allowed','Use GET ou POST.',405);
            switch ($action) {
            case 'billing_preferences':
                if ($method==='POST') {
                    $this->requireRole(['owner','manager']);
                    $email=PanelValidation::email($data,'billing_email'); $name=PanelValidation::text($data,'responsible_name',191); $preference=PanelValidation::choice($data,'preferred_method',['pix','boleto','card']);
                    $this->query('INSERT INTO billing_preferences (company_id,billing_email,responsible_name,preferred_method) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE billing_email=VALUES(billing_email),responsible_name=VALUES(responsible_name),preferred_method=VALUES(preferred_method)',[$this->company(),$email,$name,$preference]);
                }
                return ['preferences'=>$this->row('SELECT billing_email,responsible_name,preferred_method FROM billing_preferences WHERE company_id=?',[$this->company()])];
            case 'credit_requests':
                if ($method==='POST') {
                    $this->requireRole(['owner','manager']); $amount=PanelValidation::amount($data['amount']??null); $paymentMethod=PanelValidation::choice($data,'method',['pix','boleto']);
                    $this->query('INSERT INTO credit_requests (company_id,created_by,amount,method) VALUES (?,?,?,?)',[$this->company(),$this->user(),$amount,$paymentMethod]);
                    return ['id'=>$this->db->lastInsertId(),'status'=>'pending','charge_created'=>false];
                }
                return ['items'=>$this->rows('SELECT id,amount,method,status,created_at,updated_at FROM credit_requests WHERE company_id=? ORDER BY id DESC LIMIT 100',[$this->company()])];
            case 'invoices':
                if ($method!=='GET') throw new PanelError('read_only','Faturas são somente consulta nesta API.',405);
                return ['items'=>$this->rows('SELECT id,reference,description,amount,currency,status,due_date,paid_at,created_at FROM invoices WHERE company_id=? ORDER BY id DESC LIMIT 100',[$this->company()])];
            case 'balance':
                if ($method!=='GET') throw new PanelError('read_only','Saldo é somente consulta nesta API.',405);
                return ['balance'=>$this->row('SELECT COALESCE(SUM(amount),0) amount FROM balance_entries WHERE company_id=?',[$this->company()])['amount'],'items'=>$this->rows('SELECT id,type,amount,description,occurred_at FROM balance_entries WHERE company_id=? ORDER BY occurred_at DESC,id DESC LIMIT 100',[$this->company()])];
            }
        }
        if ($action==='number_orders') {
            if (!in_array($method,['GET','POST'],true)) throw new PanelError('method_not_allowed','Use GET ou POST.',405);
            if ($method==='GET') {
                $items=$this->rows("SELECT id,service,details,status,created_at FROM service_requests WHERE company_id=? AND service IN ('sms_number','whatsapp_number') AND details LIKE '%\"request_id\":%' ORDER BY id DESC LIMIT 100",[$this->company()]);
                foreach ($items as &$item) $item['details']=array_intersect_key(json_decode($item['details'],true)?:[],array_flip(['type','quantity','ddd','notes']));
                return ['items'=>$items];
            }
            $this->requireRole(['owner','manager','operator']);
            $requestId=PanelValidation::text($data,'request_id',36);
            if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$requestId)) throw new PanelError('invalid_order','Atualize o formulário e tente novamente.');
            $lines=$data['lines']??null;
            if (!is_array($lines) || count($lines)<1 || count($lines)>20 || array_keys($lines)!==range(0,count($lines)-1)) throw new PanelError('invalid_order','Informe entre 1 e 20 linhas.');
            $clean=[]; $total=0;
            foreach ($lines as $line) {
                if (!is_array($line)) throw new PanelError('invalid_order','Verifique as linhas do pedido.');
                $type=PanelValidation::choice($line,'type',['sms','whatsapp']);
                $quantity=$line['quantity']??null;
                if (!is_int($quantity) || $quantity<1 || $quantity>1000) throw new PanelError('invalid_order','A quantidade deve ser um inteiro entre 1 e 1.000.');
                $ddd=$line['ddd']??''; $notes=$line['notes']??'';
                if (!is_string($ddd) || ($ddd!=='' && !preg_match('/^[1-9][0-9]$/D',$ddd)) || !is_string($notes) || preg_match_all('/./us',$notes)>500) throw new PanelError('invalid_order','Informe um DDD com dois dígitos e observações de até 500 caracteres.');
                $total+=$quantity; $clean[]=['type'=>$type,'quantity'=>$quantity,'ddd'=>$ddd,'notes'=>trim($notes)];
            }
            if ($total>1000) throw new PanelError('invalid_order','O pedido pode conter até 1.000 números.');
            $hash=hash('sha256',json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $this->db->beginTransaction();
            try {
                $this->row('SELECT id FROM companies WHERE id=? FOR UPDATE',[$this->company()]);
                $previous=$this->rows("SELECT id,details FROM service_requests WHERE company_id=? AND service IN ('sms_number','whatsapp_number') AND details LIKE ? ORDER BY id",[$this->company(),'%"request_id":"'.$requestId.'"%']);
                if ($previous) {
                    foreach ($previous as $item) if ((json_decode($item['details'],true)['payload_hash']??'')!==$hash) throw new PanelError('order_conflict','Este pedido já foi enviado com outro conteúdo.',409);
                    $ids=array_map('strval',array_column($previous,'id'));
                } else {
                    $ids=[];
                    foreach ($clean as $line) {
                        $details=json_encode($line+['request_id'=>$requestId,'payload_hash'=>$hash],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                        $this->query("INSERT INTO service_requests (company_id,created_by,service,details,status) VALUES (?,?,?,?,'submitted')",[$this->company(),$this->user(),$line['type']==='sms'?'sms_number':'whatsapp_number',$details]);
                        $ids[]=$this->db->lastInsertId();
                    }
                }
                $this->db->commit();
                return ['ids'=>$ids,'status'=>'submitted','replayed'=>(bool)$previous,'charge_created'=>false];
            } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
        }
        throw new PanelError('not_found','Recurso não encontrado.',404);
    }
}
