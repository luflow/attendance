import { createAppConfig } from "@nextcloud/vite-config";
import { readFileSync } from "fs";
import { join, resolve } from "path";

// Read app id/version from appinfo/info.xml so we can inject them as globals.
// @nextcloud/vue expects top-level `appName` / `appVersion` identifiers; since Vite 7
// no longer emits `rollupOptions.output.intro` into our chunks, inject them via `define`
// to keep document.title and localized app name lookups working.
const infoXml = readFileSync(resolve("appinfo", "info.xml"), "utf8");
const appId = infoXml.match(/<id>([^<]+)<\/id>/i)[1];
const appVersion = infoXml.match(/<version>([^<]+)<\/version>/i)[1];

export default createAppConfig(
  {
    main: resolve(join("src", "main.js")),
    dashboard: resolve(join("src", "dashboard.js")),
    settings: resolve(join("src", "settings.js")),
    quickresponse: resolve(join("src", "quickresponse.js")),
    selfcheckin: resolve(join("src", "selfcheckin.js")),
  },
  {
    createEmptyCSSEntryPoints: true,
    extractLicenseInformation: true,
    thirdPartyLicense: false,
    config: {
      define: {
        appName: JSON.stringify(appId),
        appVersion: JSON.stringify(appVersion),
      },
      build: {
        // Suppress chunk size warning - Nextcloud apps typically have large chunks
        chunkSizeWarningLimit: 1500,
      },
    },
  }
);
