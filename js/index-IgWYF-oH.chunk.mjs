function e(t,n,r){const o=document.querySelector(`#initial-state-${t}-${n}`);if(o===null){if(r!==void 0)return r;throw new Error(`Could not find initial state ${n} of ${t}`)}try{return JSON.parse(atob(o.value))}catch{throw new Error(`Could not parse initial state ${n} of ${t}`)}}export{e as l};
//# sourceMappingURL=index-IgWYF-oH.chunk.mjs.map
