import { createAppConfig } from "@nextcloud/vite-config";
import { readFileSync } from "fs";
import { join, resolve } from "path";
import { createLogger } from "vite";

// Read app id/version from appinfo/info.xml so we can inject them as globals.
// @nextcloud/vue expects top-level `appName` / `appVersion` identifiers; since Vite 7
// no longer emits `rollupOptions.output.intro` into our chunks, inject them via `define`
// to keep document.title and localized app name lookups working.
const infoXml = readFileSync(resolve("appinfo", "info.xml"), "utf8");
const appId = infoXml.match(/<id>([^<]+)<\/id>/i)[1];
const appVersion = infoXml.match(/<version>([^<]+)<\/version>/i)[1];

// Nextcloud apps emit js/, css/ and img/ into the repo root, which is why
// @nextcloud/vite-config sets outDir to '' — vite's warning about that layout
// is unavoidable noise here, everything else still gets through.
const logger = createLogger();
const loggerWarn = logger.warn.bind(logger);
logger.warn = (msg, options) => {
  if (msg.includes("build.outDir must not be the same directory of root")) {
    return;
  }
  loggerWarn(msg, options);
};

export default (env) => {
  const isDev = env.mode === "development";

  const config = createAppConfig(
    {
      main: resolve(join("src", "main.js")),
      dashboard: resolve(join("src", "dashboard.js")),
      settings: resolve(join("src", "settings.js")),
      personal: resolve(join("src", "personal.js")),
      quickresponse: resolve(join("src", "quickresponse.js")),
      selfcheckin: resolve(join("src", "selfcheckin.js")),
    },
    {
      createEmptyCSSEntryPoints: true,
      extractLicenseInformation: true,
      thirdPartyLicense: false,
      // Skip the extra rollup-plugin-esbuild-minify pass: vite's own esbuild
      // minifier (re-enabled below) produces the same output, while the
      // plugin loudly warns about vendored code it cannot change
      // (duplicate-object-key in fast-xml-parser via webdav) on every build.
      minify: false,
      config: {
        customLogger: logger,
        define: {
          appName: JSON.stringify(appId),
          appVersion: JSON.stringify(appVersion),
        },
        build: {
          // `minify: false` above also disabled vite's internal minifier —
          // bring it back for production builds.
          minify: isDev ? false : "esbuild",
          // Suppress chunk size warning - Nextcloud apps typically have large chunks
          chunkSizeWarningLimit: 1500,
          rollupOptions: {
            onwarn(warning, warn) {
              // Dependencies ship /*#__PURE__*/ annotations in positions
              // Rollup cannot interpret (@vueuse/core) — not actionable here.
              if (
                warning.code === "INVALID_ANNOTATION" &&
                warning.id?.includes("node_modules")
              ) {
                return;
              }
              warn(warning);
            },
          },
        },
      },
    }
  );

  return typeof config === "function" ? config(env) : config;
};
