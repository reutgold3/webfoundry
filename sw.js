const CACHE="yalla-english-v6";
const CORE=["/","/index.html","/manifest.webmanifest","/icons/icon-192.png","/icons/icon-512.png"];
self.addEventListener("install",e=>{e.waitUntil(caches.open(CACHE).then(c=>c.addAll(CORE)).then(()=>self.skipWaiting()))});
self.addEventListener("activate",e=>{e.waitUntil(caches.keys().then(ks=>Promise.all(ks.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()))});
self.addEventListener("fetch",e=>{
  const r=e.request;if(r.method!=="GET")return;
  const u=new URL(r.url);
  if(u.origin===location.origin&&(r.mode==="navigate"||u.pathname==="/"||u.pathname.endsWith(".html"))){
    e.respondWith(fetch(r).then(res=>{const c=res.clone();caches.open(CACHE).then(x=>x.put("/index.html",c));return res}).catch(()=>caches.match("/index.html")));
    return;
  }
  e.respondWith(caches.match(r).then(hit=>hit||fetch(r).then(res=>{if(res.ok&&(u.origin===location.origin||u.host.includes("fonts.g"))){const c=res.clone();caches.open(CACHE).then(x=>x.put(r,c))}return res})));
});
