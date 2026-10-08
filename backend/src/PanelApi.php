<?php
declare(strict_types=1);
namespace Pulse;
require_once __DIR__.'/PanelValidation.php';
require_once __DIR__.'/ChipeiraGateway.php';

final class PanelApi {
    private \PDO $db;
    private string $secret;
    private ?array $session = null;
    private string $token = '';
    private array $chipeira;
    private array $selectionPolicy;
    public function __construct(\PDO $db, string $secret, array $chipeira = [], array $selectionPolicy = []) {
        $this->db = $db; $this->secret = $secret;
        $this->chipeira = $chipeira;
        $this->selectionPolicy = $selectionPolicy;
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
        return ['authenticated'=>true,'user'=>['id'=>(string)$this->session['user_id'],'name'=>$this->session['user_name'],'email'=>$this->session['email']], 'company'=>['id'=>$this->company(),'name'=>$this->session['company_name']], 'role'=>$this->session['role'],'csrf_token'=>$this->csrfToken(),'uploads_available'=>false];
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
            if (in_array($data['op'] ?? '', ['available','reserve'], true)) {
                if ($method==='POST') $this->requireRole(['owner','manager']);
                $paid = $this->row("SELECT id FROM payments WHERE company_id=? AND status IN ('approved','partially_refunded') AND credited_amount>reversed_amount LIMIT 1",[$this->company()]);
                $balance = $this->row('SELECT COALESCE(SUM(amount),0) amount FROM balance_entries WHERE company_id=?',[$this->company()]);
                $testAccess = ($this->selectionPolicy['local_test'] ?? false) === true && in_array($this->session['email'] ?? '', $this->selectionPolicy['test_emails'] ?? [], true);
                $eligible = $testAccess || ($paid !== null && (float)$balance['amount'] > 0);
                if (!$eligible) {
                    if ($method==='GET') return ['eligible'=>false,'phones'=>[],'reason'=>'A escolha de números será liberada após um pagamento confirmado e com saldo disponível.'];
                    throw new PanelError('payment_required','É necessário pagamento confirmado e saldo disponível para escolher um número.',402);
                }
                $result = (new ChipeiraGateway($this->chipeira))->handle($this->company(),$method,$data);
                return $result + ['eligible'=>true,'test_access'=>$testAccess];
            }
            return (new ChipeiraGateway($this->chipeira))->handle($this->company(),$method,$data);
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
        throw new PanelError('not_found','Recurso não encontrado.',404);
    }
}
