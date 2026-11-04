# Prueba los casos de error del flujo 1 con datos ficticios:
#   1. Evento sin lineas: debe devolver 422.
#   2. Mismo evento dos veces: la segunda vez no debe crear otro pedido.
# El token se pide por teclado, no se muestra y no se guarda.

param(
    [string]$Webhook = 'https://noctiluz-n8n.onrender.com/webhook/noctiluz-eventos',
    [string]$Payloads = (Join-Path $PSScriptRoot '..\..\..\Tarea 1 SDE\entrega_final\docs\payloads')
)

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$seguro = Read-Host 'Pega el token de la tienda (sin Bearer)' -AsSecureString
$puntero = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($seguro)
$token = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($puntero)
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($puntero)

function Enviar([string]$Titulo, [string]$Archivo) {
    $ruta = Join-Path $Payloads $Archivo
    $cuerpo = [IO.File]::ReadAllBytes($ruta)
    $cabeceras = @{ Authorization = "Bearer $token" }
    Write-Host ''
    Write-Host "== $Titulo"
    try {
        $r = Invoke-WebRequest -Uri $Webhook -Method Post -Headers $cabeceras -ContentType 'application/json; charset=utf-8' -Body $cuerpo -UseBasicParsing -TimeoutSec 90
        Write-Host "HTTP $($r.StatusCode)"
        Write-Host $r.Content
    } catch {
        $respuesta = $_.Exception.Response
        if ($null -eq $respuesta) {
            Write-Host "Sin respuesta: $($_.Exception.Message)"
            return
        }
        Write-Host "HTTP $([int]$respuesta.StatusCode)"
        $lector = New-Object IO.StreamReader($respuesta.GetResponseStream())
        Write-Host $lector.ReadToEnd()
    }
}

Enviar 'Evento sin lineas (esperado: 422)' 'error_pedido_sin_lineas.json'
Enviar 'Pedido, primer envio (esperado: 201)' 'escenario1_order_created.json'
Enviar 'Mismo pedido, segundo envio (esperado: duplicado)' 'escenario1_order_created.json'
Write-Host ''
Write-Host 'Fin de la prueba.'
