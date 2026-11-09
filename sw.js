const C='yalla-anatomy-v12';
const CORE=['/','/index.html','/vendor/three.min.js','/vendor/OrbitControls.js','/data/skeleton.json','/data/names.json','/data/skeleton.bin','/icons/icon-192.png','/privacy.html','/terms.html','/accessibility.html'];
// cache:'reload' so an update never stores a stale copy from the browser's HTTP cache
self.addEventListener('install',e=>{e.waitUntil(caches.open(C).then(c=>c.addAll(CORE.map(u=>new Request(u,{cache:'reload'})))).then(()=>self.skipWaiting()))});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(ks=>Promise.all(ks.filter(k=>k!==C).map(k=>caches.delete(k)))).then(()=>self.clients.claim()))});
const put=(k,r)=>{if(r.ok){const cp=r.clone();caches.open(C).then(c=>c.put(k,cp))}return r};
self.addEventListener('fetch',e=>{const u=new URL(e.request.url);if(e.request.method!=='GET'||u.origin!==location.origin)return;
 // pages and text data (names, descriptions): network first, so content updates show at once; offline falls back to the cache
 if(e.request.mode==='navigate'){e.respondWith(fetch(e.request,{cache:'no-cache'}).then(r=>put('/index.html',r)).catch(()=>caches.match('/index.html')));return}
 if(/\.(json|html|webmanifest)$/.test(u.pathname)){e.respondWith(fetch(e.request,{cache:'no-cache'}).then(r=>put(e.request,r)).catch(()=>caches.match(e.request)));return}
 // big 3D model files: cache first
 e.respondWith(caches.match(e.request).then(h=>h||fetch(e.request).then(r=>put(e.request,r))))});
