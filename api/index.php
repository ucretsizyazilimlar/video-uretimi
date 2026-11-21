<?php
// ============================================================
// api/index.php — Vercel sürümü
// Senaryo üretimi Vercel'de, video render ayrı sunucuda
// ============================================================

$api_key      = '';
$app_password = '';

$text_model = 'nvidia/nemotron-3.5-bolt-e4b';
$text_url   = 'https://integrate.api.nvidia.com/v1/chat/completions';

// FFmpeg sunucusunun adresi (ayrı VPS/hosting)
$render_server = 'https://senin-sunucun.com/video-render.php';

$scene_count   = 3;
$scene_seconds = 4;
$max_img_bytes = 10 * 1024 * 1024;

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function check_auth(string $pw): void {
    if ($pw === '') return;
    if (!hash_equals($pw, $_SERVER['HTTP_X_AUTH'] ?? '')) json_out(['error' => 'Yetkisiz: parola yanlış.'], 401);
}

function nv_post(string $url, array $payload, string $key, int $timeout = 120): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$body, $err];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'script') {
    @set_time_limit(280);
    check_auth($app_password);

    if (strpos($api_key, 'nvapi-') !== 0) json_out(['error' => 'NVIDIA API anahtarı ayarlanmamış.'], 500);

    $topic = trim((string)($_POST['topic'] ?? ''));
    if ($topic === '' || mb_strlen($topic) > 300) json_out(['error' => 'Konu boş veya çok uzun.'], 400);

    $imgs = [];
    for ($i = 0; $i < $scene_count; $i++) {
        $f = $_FILES['img' . $i] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) json_out(['error' => 'Resim ' . ($i + 1) . ' yüklenemedi.'], 400);
        if ($f['size'] > $max_img_bytes) json_out(['error' => 'Resim ' . ($i + 1) . ' 10 MB sınırını aşıyor.'], 400);
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            json_out(['error' => 'Resim ' . ($i + 1) . ' geçerli JPG/PNG/WEBP değil.'], 400);
        }
        $imgs[$i] = base64_encode(file_get_contents($f['tmp_name']));
    }

    $sys = "Sen bir YouTube Shorts senaristisin. Verilen konu için TAM {$scene_count} sahne yaz; her sahne {$scene_seconds} saniye sürecek. "
         . "Sahneler akış oluştursun: 1. dikkat çeken giriş, 2. ana bilgi, 3. kapanış/çağrı. "
         . "Her sahne için \"text\" = ekranda görünecek Türkçe kısa yazı (en fazla 12 kelime). "
         . "Sadece geçerli JSON döndür: {\"title\":\"...\",\"scenes\":[{\"text\":\"...\"}]}";

    [$code, $body, $err] = nv_post($text_url, [
        'model' => $text_model,
        'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => "Konu: " . $topic]],
        'temperature' => 0.8, 'top_p' => 0.95, 'max_tokens' => 800, 'stream' => false,
        'chat_template_kwargs' => ['enable_thinking' => false],
    ], $api_key, 90);

    if ($err) json_out(['error' => 'cURL hatası: ' . $err], 502);
    if ($code >= 400) json_out(['error' => "NVIDIA API HTTP $code", 'detail' => mb_substr($body, 0, 500)], 502);

    $resp = json_decode($body, true);
    $content = $resp['choices'][0]['message']['content'] ?? '';
    if (!preg_match('/\{.*\}/s', $content, $m)) json_out(['error' => 'Model JSON döndürmedi.'], 502);
    $data = json_decode($m[0], true);
    $scenes = is_array($data['scenes'] ?? null) ? array_values($data['scenes']) : [];
    if (count($scenes) < $scene_count) json_out(['error' => 'Model yeterli sahne üretmedi.'], 502);

    $texts = [];
    for ($i = 0; $i < $scene_count; $i++) $texts[] = trim((string)($scenes[$i]['text'] ?? ''));

    // Render sunucusuna gönder
    $ch = curl_init($render_server);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'title' => (string)($data['title'] ?? $topic),
            'texts' => $texts,
            'images' => $imgs,
            'password' => $app_password,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 280,
    ]);
    $render_resp = curl_exec($ch);
    $render_err  = curl_error($ch);
    $render_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($render_err) json_out(['error' => 'Render sunucusuna bağlanılamadı: ' . $render_err], 502);
    if ($render_code >= 400) json_out(['error' => 'Render hatası', 'detail' => mb_substr((string)$render_resp, 0, 500)], 502);

    $render_data = json_decode((string)$render_resp, true);
    if (!is_array($render_data) || empty($render_data['url'])) {
        json_out(['error' => 'Render sunucusu geçersiz yanıt döndü.'], 502);
    }

    json_out(['title' => (string)($data['title'] ?? $topic), 'texts' => $texts, 'url' => $render_data['url']]);
}

// Arayüz (HTML) aşağıda...
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Video Üretici — Vercel</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f0f12; color: #e4e4e7; padding: 20px; display: flex; justify-content: center; }
    .wrap { width: 100%; max-width: 980px; }
    h1 { font-size: 22px; margin-bottom: 4px; color: #fff; }
    .sub { font-size: 13px; color: #71717a; margin-bottom: 18px; }
    .row { display: flex; gap: 8px; margin-bottom: 16px; align-items: center; }
    input[type=text], textarea { width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid #27272a; background: #18181b; color: #e4e4e7; font-size: 15px; font-family: inherit; outline: none; }
    input:focus, textarea:focus { border-color: #2563eb; }
    textarea { resize: vertical; min-height: 80px; margin-top: 6px; }
    button { padding: 12px 20px; border-radius: 10px; border: none; color: #fff; background: #2563eb; font-size: 15px; font-weight: 600; cursor: pointer; white-space: nowrap; }
    button:hover { background: #1d4ed8; }
    button:disabled { background: #27272a; color: #52525b; cursor: not-allowed; }
    .scenes { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
    @media (max-width: 760px) { .scenes { grid-template-columns: 1fr; } }
    .card { background: #18181b; border: 1px solid #27272a; border-radius: 12px; padding: 12px; }
    .card h3 { font-size: 13px; color: #a1a1aa; margin-bottom: 8px; }
    .thumb { aspect-ratio: 9/16; background: #0f0f12; border: 1px dashed #3f3f46; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #71717a; font-size: 13px; overflow: hidden; cursor: pointer; text-align: center; padding: 8px; }
    .thumb img { width: 100%; height: 100%; object-fit: cover; }
    .thumb.filled { border-style: solid; padding: 0; }
    label { font-size: 12px; color: #71717a; display: block; margin-top: 8px; }
    #status { font-size: 13px; color: #a1a1aa; min-height: 18px; }
    #status.err { color: #fca5a5; white-space: pre-wrap; }
    video { max-width: 320px; width: 100%; border-radius: 12px; background: #000; }
    .result { display: none; margin-top: 12px; }
    .result a { color: #86efac; }
    input[type=file] { display: none; }
    .footer { margin-top: 20px; text-align: center; font-size: 13px; color: #71717a; }
    .footer a { color: #60a5fa; text-decoration: none; }
    .footer a:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="wrap">
    <h1>🎬 Video Üretici <span style="font-size:14px;color:#71717a;font-weight:400">12 sn · 3 resim × 4 sn</span></h1>
    <div class="sub">3 resim seç → konu yaz → bot senaryo yazsın → video ayrı sunucuda oluşturulsun.</div>

    <div class="scenes" id="scenes"></div>

    <div class="row">
        <input type="text" id="topic" placeholder="Video konusu (örn: Uzayda yaşam mümkün mü?)">
        <button id="go-btn">Video Oluştur</button>
    </div>

    <div class="row">
        <div id="status"></div>
    </div>

    <div class="result" id="result">
        <video id="player" controls playsinline></video>
        <p style="margin-top:8px"><a id="dl" href="#" download>⬇ MP4 indir</a></p>
    </div>

    <div class="footer">
        <a href="https://github.com/ucretsizyazilimlar/kisiselbot" target="_blank" rel="noopener noreferrer">
            github.com/ucretsizyazilimlar/kisiselbot
        </a>
    </div>
</div>

<script>
(function () {
    const SCENES = 3;
    const $ = id => document.getElementById(id);

    function pw(force) {
        let p = sessionStorage.getItem('vpw');
        if (p === null || force) { p = prompt('Parola:') || ''; sessionStorage.setItem('vpw', p); }
        return p;
    }
    function status(msg, err) { const s = $('status'); s.textContent = msg; s.className = err ? 'err' : ''; }

    async function call(action, body, isForm) {
        const headers = { 'X-Auth': pw(false) };
        if (!isForm) headers['Content-Type'] = 'application/json';
        const res = await fetch('?action=' + action, { method: 'POST', headers: headers, body: isForm ? body : JSON.stringify(body) });
        const data = await res.json().catch(() => ({ error: 'Sunucu JSON döndürmedi (HTTP ' + res.status + ')' }));
        if (res.status === 401) sessionStorage.removeItem('vpw');
        if (!res.ok || data.error) throw new Error(data.error + (data.detail ? '\n' + data.detail : ''));
        return data;
    }

    const box = $('scenes');
    for (let i = 0; i < SCENES; i++) {
        const c = document.createElement('div');
        c.className = 'card';
        c.innerHTML = '<h3>Sahne ' + (i + 1) + ' · ' + (i * 4) + '–' + ((i + 1) * 4) + ' sn</h3>' +
            '<div class="thumb" id="thumb' + i + '">📁 Resim seç<br>(tıkla)</div>' +
            '<input type="file" id="file' + i + '" accept="image/jpeg,image/png,image/webp">' +
            '<label>Ekran yazısı (bot dolduracak)</label>' +
            '<textarea id="text' + i + '" placeholder="Senaryo yazılınca burada görünür"></textarea>';
        box.appendChild(c);
        $('thumb' + i).addEventListener('click', () => $('file' + i).click());
        $('file' + i).addEventListener('change', e => {
            const f = e.target.files[0];
            if (!f) return;
            $('thumb' + i).innerHTML = '<img src="' + URL.createObjectURL(f) + '">';
            $('thumb' + i).classList.add('filled');
        });
    }

    $('go-btn').addEventListener('click', async () => {
        const topic = $('topic').value.trim();
        if (!topic) { status('⚠ Konu yaz.', true); return; }
        const fd = new FormData();
        fd.append('topic', topic);
        for (let i = 0; i < SCENES; i++) {
            const f = $('file' + i).files[0];
            if (!f) { status('⚠ Önce 3 resmin hepsini seç (Sahne ' + (i + 1) + ' eksik).', true); return; }
            fd.append('img' + i, f);
        }
        $('go-btn').disabled = true; $('result').style.display = 'none';
        try {
            status('Senaryo yazılıyor ve video oluşturuluyor… 30-90 sn sürebilir');
            const d = await call('script', fd, true);
            d.texts.forEach((t, i) => { $('text' + i).value = t; });
            $('player').src = d.url; $('dl').href = d.url;
            $('result').style.display = 'block';
            status('✅ Video hazır.');
        } catch (e) { status('⚠ ' + e.message, true); }
        finally { $('go-btn').disabled = false; }
    });

    $('topic').addEventListener('keydown', e => { if (e.key === 'Enter') $('go-btn').click(); });
})();
    <link>https://github.com/crypto2324/video-uretici</link>
</script>
</body>
</html>
