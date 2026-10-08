<?php
declare(strict_types=1);
namespace Pulse;
require_once __DIR__.'/PanelValidation.php';

final class ChipeiraGateway {
    private array $config;
    private $transport;
    public function __construct(array $config, ?callable $transport = null) {
        $this->config = $config;
        $this->transport = $transport;
    }
    public function handle(string $company, string $method, array $data): array {
        if (($this->config['enabled'] ?? false) !== true || strlen($this->config['api_key'] ?? '') < 32) {
            if ($method === 'GET' && ($data['op'] ?? 'status') === 'status') return ['connected'=>false,'configured'=>false,'channels'=>['sms']];
            throw new PanelError('chipeira_not_configured','A conexão com a chipeira ainda não foi configurada.',503);
        }
        if (!preg_match('/^[1-9]\d{0,19}$/D',$company)) throw new PanelError('invalid_company','Empresa inválida.');
        $op = $data['op'] ?? 'status';
        $verb = 'GET'; $body = null;
        if ($method === 'GET') {
            switch ($op) {
                case 'status': $path='/status'; break;
                case 'phones': $path='/phones'; break;
                case 'available': $path='/phones/available'; break;
                case 'messages':
                    $number=PanelValidation::text($data,'number',32);
                    if (!preg_match('/^\+?[0-9]{10,15}$/D',$number)) throw new PanelError('invalid_number','Número inválido.');
                    $path='/phones/'.rawurlencode($number).'/messages'; break;
                case 'campaigns': $path='/campaigns'; break;
                case 'campaign': $path='/campaigns/'.PanelValidation::id($data['id']??null); break;
                default: throw new PanelError('invalid_operation','Operação inválida.');
            }
        } else {
            if ($op==='reserve') {
                if ($method!=='POST') throw new PanelError('method_not_allowed','Use POST.',405);
                $number=PanelValidation::text($data,'number',32);
                if (!preg_match('/^\+?[0-9]{10,15}$/D',$number)) throw new PanelError('invalid_number','Número inválido.');
                $verb='POST'; $path='/phones/reserve'; $body=['number'=>$number];
            }
            elseif ($op==='cancel') { $verb='PATCH'; $path='/campaigns/'.PanelValidation::id($data['id']??null); $body=['status'=>'cancelled']; }
            elseif ($op==='create') {
                $verb='POST'; $path='/campaigns';
                $requestId=PanelValidation::text($data,'request_id',80);
                if (!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$requestId) || ($data['consent']??false)!==true) throw new PanelError('invalid_dispatch','Revise a campanha e confirme a autorização.');
                $recipients=$data['recipients']??null; $from=$data['from']??null;
                if (!is_array($recipients) || array_keys($recipients)!==range(0,count($recipients)-1) || count($recipients)<1 || count($recipients)>500 || !is_array($from) || array_keys($from)!==range(0,count($from)-1) || count($from)<1 || count($from)>64) throw new PanelError('invalid_dispatch','Informe remetentes e até 500 destinatários.');
                foreach (array_merge($recipients,$from) as $number) if (!is_string($number) || !preg_match('/^\+?[0-9]{10,15}$/D',$number)) throw new PanelError('invalid_number','Número inválido.');
                $body=['requestId'=>$requestId,'name'=>PanelValidation::text($data,'name',120),'message'=>PanelValidation::text($data,'message',6400),'recipients'=>$recipients,'from'=>$from,'consent'=>true];
            } else throw new PanelError('invalid_operation','Operação inválida.');
        }
        $base=$this->baseUrl();
        $result=$this->http($base.'/api/integration/pulse'.$path,$verb,$body,['Accept: application/json','x-api-key: '.$this->config['api_key'],'x-pulse-company: '.$company]);
        unset($result['success']);
        if ($op==='status') $result += ['connected'=>true,'configured'=>true];
        return $result;
    }
    public static function validateBaseUrl(string $url, bool $local = false): string {
        $parts=parse_url($url);
        if (!$parts || !isset($parts['scheme'],$parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path']??'',['','/'],true)) throw new PanelError('invalid_gateway_url','Endereço da chipeira inválido.',503);
        $host=strtolower($parts['host']);
        if ($parts['scheme']!=='https' && !($local && $parts['scheme']==='http' && in_array($host,['localhost','127.0.0.1'],true))) throw new PanelError('invalid_gateway_url','A conexão exige HTTPS.',503);
        return rtrim($url,'/');
    }
    private function baseUrl(): string {
        if (!empty($this->config['base_url'])) return self::validateBaseUrl($this->config['base_url'],($this->config['allow_local_http']??false)===true);
        $js=$this->http('https://cscall.com.br/runtime-config.js','GET',null,[],true);
        if (!preg_match('/__CHIPEIRA_API_URL__\s*=\s*["\x27](https:\/\/[^"\x27]+)["\x27]/',$js,$matches)) throw new PanelError('chipeira_offline','Endereço da chipeira não publicado.',503);
        $url=self::validateBaseUrl($matches[1]);
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        $allowed=$this->config['discovery_hosts']??[];
        if (!preg_match('/^[a-z0-9-]+\.trycloudflare\.com$/D',$host) && !in_array($host,$allowed,true)) throw new PanelError('invalid_gateway_url','Host publicado não autorizado. Configure uma URL fixa.',503);
        return $url;
    }
    private function http(string $url, string $verb, ?array $body, array $headers, bool $raw = false) {
        if ($this->transport) return ($this->transport)($url,$verb,$body,$headers,$raw);
        if (!function_exists('curl_init')) throw new PanelError('curl_required','Ative a extensão cURL no servidor PHP.',503);
        $response=''; $ch=curl_init($url);
        $options=[CURLOPT_CUSTOMREQUEST=>$verb,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$response): int { if (strlen($response)+strlen($chunk)>2097152) return 0; $response.=$chunk; return strlen($chunk); }];
        if ($body!==null) { $options[CURLOPT_POSTFIELDS]=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); $options[CURLOPT_HTTPHEADER][]='Content-Type: application/json'; }
        curl_setopt_array($ch,$options); $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if ($ok===false || $status===0) throw new PanelError('chipeira_offline','Sem resposta da chipeira. Uma campanha pode ter sido aceita; atualize o histórico antes de reenviar.',503);
        if ($raw) {
            if ($status!==200) throw new PanelError('chipeira_offline','Não foi possível localizar a chipeira.',503);
            return $response;
        }
        $json=json_decode($response,true);
        if (!is_array($json) || $status>=300 || ($json['success']??false)!==true) {
            $code=in_array($status,[400,404,409,429],true)?$status:503;
            $message=$code===503?'Não foi possível conectar à chipeira. Verifique a configuração.':(is_string($json['message']??null)?substr($json['message'],0,500):'Operação recusada.');
            throw new PanelError('chipeira_error',$message,$code);
        }
        return $json;
    }
}
