# Vendored viewer libraries

Loaded locally so the viewer works offline and does not depend on CDN availability.

| File | Package | Source |
|------|---------|--------|
| `graphology.umd.min.js` | graphology 0.25.4 | https://cdnjs.cloudflare.com/ajax/libs/graphology/0.25.4/graphology.umd.min.js |
| `sigma.min.js` | sigma 2.4.0 | https://cdnjs.cloudflare.com/ajax/libs/sigma.js/2.4.0/sigma.min.js |
| `graphology-layout-forceatlas2.min.js` | graphology-layout-forceatlas2 0.10.1 | built, the package ships no browser build |

All MIT licensed, see `LICENSES.txt`.

Rebuilding ForceAtlas2:

```bash
npm i graphology-layout-forceatlas2@0.10.1 esbuild
echo "module.exports = require('graphology-layout-forceatlas2');" > entry.js
npx esbuild entry.js --bundle --minify --format=iife --global-name=graphologyLayoutForceAtlas2 \
  --outfile=graphology-layout-forceatlas2.min.js
```
