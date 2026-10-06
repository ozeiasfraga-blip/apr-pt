package br.net.nwt.aprpt;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.graphics.Color;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Message;
import android.webkit.CookieManager;
import android.webkit.JavascriptInterface;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Toast;

import java.io.IOException;
import java.util.HashMap;
import java.util.Map;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * APR/PT — app Android nativo (WebView).
 *
 * Carrega o sistema em http://177.125.60.123:8089/ em tela cheia (sem a barra
 * do navegador). As telas e a lógica continuam no servidor: qualquer mudança
 * na web chega automaticamente, sem reinstalar o app.
 *
 * Recursos tratados aqui:
 *  - botão Voltar navega no histórico do app;
 *  - PDFs e o APK de atualização são baixados (com o cookie de sessão) e abertos
 *    no app correspondente; no caso do APK, abre direto o instalador;
 *  - links externos (e-mail, telefone, outros sites) abrem fora do app;
 *  - página de "sem conexão" com botão de tentar novamente;
 *  - expõe window.AprptApp.versao() para a interface saber que está no app nativo.
 */
public class MainActivity extends Activity {

    private static final String HOME_URL = "http://177.125.60.123:8089/";
    private static final String HOST = "177.125.60.123";

    private WebView webView;
    private boolean erroVisivel = false;
    private final Map<Long, Boolean> downloads = new HashMap<>(); // id -> é APK?

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        webView = new WebView(this);
        webView.setBackgroundColor(Color.parseColor("#0D3B66"));
        setContentView(webView);

        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(false);
        s.setSupportMultipleWindows(true);
        s.setJavaScriptCanOpenWindowsAutomatically(true);

        CookieManager.getInstance().setAcceptCookie(true);
        webView.addJavascriptInterface(new AppBridge(), "AprptApp");

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                return tratarUrl(request.getUrl().toString());
            }

            /**
             * Sem internet: serve o "casco" do app empacotado no APK (assets/site),
             * para as telas abrirem mesmo sem rede. Com rede, deixa o WebView
             * buscar normalmente no servidor (sempre a versão mais nova).
             */
            @Override
            public WebResourceResponse shouldInterceptRequest(WebView view, WebResourceRequest request) {
                if (!"GET".equalsIgnoreCase(request.getMethod())) return null;
                Uri url = request.getUrl();
                if (!HOST.equals(url.getHost())) return null;
                String path = url.getPath() == null ? "/" : url.getPath();
                if (path.startsWith("/api/") || path.startsWith("/apk")) return null; // sempre rede
                if (temRede()) return null;              // online: fluxo normal do WebView
                String asset = assetDoCasco(path);
                if (asset == null) return null;
                try {
                    return new WebResourceResponse(mime(asset), "utf-8", getAssets().open(asset));
                } catch (IOException e) {
                    return null;
                }
            }

            @Override
            public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
                if (url != null && url.startsWith("http")) erroVisivel = false;
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) mostrarSemConexao();
            }
        });

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onCreateWindow(WebView view, boolean isDialog, boolean isUserGesture, Message resultMsg) {
                // target="_blank" / window.open: carrega no próprio app (ou baixa o arquivo)
                final WebView popup = new WebView(MainActivity.this);
                popup.setWebViewClient(new WebViewClient() {
                    @Override
                    public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest request) {
                        final String url = request.getUrl().toString();
                        webView.post(() -> {
                            if (!tratarUrl(url)) webView.loadUrl(url);
                            v.destroy();
                        });
                        return true;
                    }
                });
                ((WebView.WebViewTransport) resultMsg.obj).setWebView(popup);
                resultMsg.sendToTarget();
                return true;
            }
        });

        webView.setDownloadListener((url, userAgent, contentDisposition, mimeType, contentLength) ->
                baixar(url, url.toLowerCase().endsWith(".apk")));

        IntentFilter filtro = new IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE);
        if (Build.VERSION.SDK_INT >= 33) {
            registerReceiver(downloadReceiver, filtro, Context.RECEIVER_EXPORTED);
        } else {
            registerReceiver(downloadReceiver, filtro);
        }

        webView.loadUrl(HOME_URL);
    }

    /** Decide o que fazer com cada URL. Retorna true se o WebView não deve carregá-la. */
    private boolean tratarUrl(String url) {
        Uri uri = Uri.parse(url);
        String scheme = uri.getScheme();
        if (scheme == null) return false;

        if ("http".equals(scheme) || "https".equals(scheme)) {
            String host = uri.getHost();
            if (HOST.equals(host)) {
                String path = uri.getPath() == null ? "" : uri.getPath().toLowerCase();
                if (path.endsWith(".apk")) {
                    baixar(url, true);   // atualização do app
                    return true;
                }
                if (path.endsWith("/pdf")) {
                    baixar(url, false);  // documento em PDF
                    return true;
                }
                return false;            // navega normalmente dentro do app
            }
            abrirExterno(uri);
            return true;
        }
        if ("mailto".equals(scheme) || "tel".equals(scheme) || "whatsapp".equals(scheme)) {
            abrirExterno(uri);
            return true;
        }
        return true; // esquemas desconhecidos: ignora
    }

    /** Há internet disponível? (sem rede o app usa o casco empacotado no APK) */
    private boolean temRede() {
        try {
            ConnectivityManager cm = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
            if (cm == null) return true;
            Network rede = cm.getActiveNetwork();
            if (rede == null) return false;
            NetworkCapabilities caps = cm.getNetworkCapabilities(rede);
            return caps != null && caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);
        } catch (Exception e) {
            return true;
        }
    }

    /** Caminho do recurso dentro do casco empacotado (assets/site) — ou null se não temos cópia. */
    private String assetDoCasco(String path) {
        if (path.equals("/") || path.equals("/index.php")) return "site/index.html";
        if (!path.startsWith("/assets/") && !path.equals("/manifest.webmanifest")) return null;
        String nome = "site" + path;
        try {
            getAssets().open(nome).close();
            return nome;
        } catch (IOException e) {
            return null;
        }
    }

    private String mime(String asset) {
        if (asset.endsWith(".html")) return "text/html";
        if (asset.endsWith(".css")) return "text/css";
        if (asset.endsWith(".js")) return "application/javascript";
        if (asset.endsWith(".png")) return "image/png";
        if (asset.endsWith(".webmanifest")) return "application/manifest+json";
        return "text/plain";
    }

    private void abrirExterno(Uri uri) {
        try {
            Intent i = new Intent(Intent.ACTION_VIEW, uri);
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            startActivity(i);
        } catch (ActivityNotFoundException e) {
            Toast.makeText(this, "Não há app instalado para abrir este link.", Toast.LENGTH_LONG).show();
        }
    }

    private void baixar(String url, boolean apk) {
        try {
            String cookie = CookieManager.getInstance().getCookie(url);
            String nome = apk ? nomeApk() : nomePdf(url);
            String mime = apk ? "application/vnd.android.package-archive" : "application/pdf";

            DownloadManager.Request req = new DownloadManager.Request(Uri.parse(url));
            req.setTitle(nome);
            req.setMimeType(mime);
            req.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            req.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, nome);
            if (cookie != null && !cookie.isEmpty()) req.addRequestHeader("Cookie", cookie);

            DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            if (dm == null) throw new IllegalStateException("DownloadManager indisponível");
            downloads.put(dm.enqueue(req), apk);
            Toast.makeText(this, apk ? "Baixando atualização…" : "Baixando PDF…", Toast.LENGTH_SHORT).show();
        } catch (Exception e) {
            Toast.makeText(this, "Não foi possível baixar o arquivo.", Toast.LENGTH_LONG).show();
        }
    }

    private String nomeApk() {
        String v = "aprpt";
        try {
            v = getPackageManager().getPackageInfo(getPackageName(), 0).versionName;
        } catch (Exception ignored) {
            // usa o nome padrão
        }
        return "aprpt-" + v + ".apk";
    }

    private String nomePdf(String url) {
        Matcher m = Pattern.compile("/api/documentos/(\\d+)/pdf").matcher(url);
        String id = m.find() ? m.group(1) : String.valueOf(System.currentTimeMillis());
        return "aprpt-documento-" + id + ".pdf";
    }

    private final BroadcastReceiver downloadReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            long id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1);
            Boolean apk = downloads.remove(id);
            if (apk == null) return;

            DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            Uri uri = dm == null ? null : dm.getUriForDownloadedFile(id);
            if (uri == null) {
                Toast.makeText(MainActivity.this, "Falha ao baixar o arquivo.", Toast.LENGTH_LONG).show();
                return;
            }

            Intent i = new Intent(Intent.ACTION_VIEW);
            i.setDataAndType(uri, apk ? "application/vnd.android.package-archive" : "application/pdf");
            i.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
            try {
                startActivity(i);
            } catch (ActivityNotFoundException e) {
                Toast.makeText(MainActivity.this, "Abra o arquivo pela pasta Downloads.", Toast.LENGTH_LONG).show();
            }
        }
    };

    private void mostrarSemConexao() {
        if (erroVisivel) return;
        erroVisivel = true;
        String html = "<!doctype html><html lang=\"pt-BR\"><head><meta charset=\"utf-8\">"
                + "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>Sem conexão</title></head>"
                + "<body style=\"margin:0;height:100vh;display:flex;align-items:center;justify-content:center;"
                + "background:#0d3b66;color:#fff;font-family:system-ui,-apple-system,sans-serif;text-align:center\">"
                + "<div style=\"padding:32px\"><div style=\"font-size:52px\">&#128225;</div>"
                + "<h1 style=\"font-size:20px;margin:12px 0 6px\">Sem conexão com o servidor</h1>"
                + "<p style=\"opacity:.75;font-size:14px;margin:0 0 24px\">Verifique a rede e tente novamente.</p>"
                + "<a href=\"/\" style=\"display:inline-block;background:#fff;color:#0d3b66;font-weight:600;"
                + "text-decoration:none;padding:12px 28px;border-radius:10px\">Tentar novamente</a></div></body></html>";
        webView.loadDataWithBaseURL(HOME_URL, html, "text/html", "UTF-8", null);
    }

    /** Ponte JS: permite à interface detectar o app nativo (window.AprptApp). */
    private class AppBridge {
        @JavascriptInterface
        public String versao() {
            return BuildConfig.VERSION_NAME;
        }

        @JavascriptInterface
        public String plataforma() {
            return "android";
        }
    }

    @Override
    public void onBackPressed() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }

    @Override
    protected void onDestroy() {
        try {
            unregisterReceiver(downloadReceiver);
        } catch (Exception ignored) {
            // receptor já removido
        }
        super.onDestroy();
    }
}
