# Deniz Arat'ın Satranç Evi

Tarayıcıda çalışan satranç uygulaması. Bilgisayara karşı oynamak için Stockfish
(WASM) motorunu, online oda oyunu için PHP tabanlı basit bir backend'i kullanır.

## Canlı sürüm

https://sabrierdem.github.io/satranc/

## Yapı

| Yol | Açıklama |
| --- | --- |
| `index.html` | Tüm arayüz, oyun mantığı ve istemci kodu |
| `js/stockfish.js`, `js/stockfish.wasm` | Satranç motoru (Web Worker olarak çalışır) |
| `img/` | Taş görselleri ve favicon'lar |
| `api/*.php` | Online oda backend'i (oda oluştur/katıl, hamle, sohbet, kaydet/yükle) |

## GitHub Pages hakkında önemli not

GitHub Pages **yalnızca statik dosya** sunar, PHP çalıştırmaz. Yayındaki sürümde:

- ✅ **Bilgisayara karşı oyun** tam çalışır — Stockfish tarayıcıda çalışıyor,
  tek thread'li olduğu için `SharedArrayBuffer` / COOP-COEP başlığı gerekmiyor.
- ❌ **Online oda / çok oyunculu mod** çalışmaz — `api/*.php` dosyaları depoda
  duruyor ama Pages üzerinde çalıştırılmıyor.

Çok oyunculu modu açmak için `api/` klasörünü PHP çalıştırabilen bir sunucuda
barındırıp `index.html` içindeki API tabanını (satır ~566) o adrese yöneltmek
gerekir:

```js
const api = (path) => `api/${path}`;
// örn: const api = (path) => `https://sunucum.example.com/api/${path}`;
```

Bu durumda PHP sunucusunda CORS başlıklarının (`Access-Control-Allow-Origin`)
Pages adresine izin vermesi gerekir.

## Yerelde çalıştırma

Tam sürüm (PHP dahil) için depo kökünde:

```bash
php -S localhost:8000
```

Sadece bilgisayara karşı oynamak yeterliyse herhangi bir statik sunucu iş görür:

```bash
python3 -m http.server 8000
```

`api/_rooms/` ve `api/_saved_games/` klasörleri çalışma anında yazılan verileri
tutar; içerikleri `.gitignore` ile depodan hariç tutulmuştur.
