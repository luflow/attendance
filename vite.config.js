import { createAppConfig } from "@nextcloud/vite-config";
import { join, resolve } from "path";

export default createAppConfig(
  {
    main: resolve(join("src", "main.js")),
    dashboard: resolve(join("src", "dashboard.js")),
    settings: resolve(join("src", "settings.js")),
  },
  {
    createEmptyCSSEntryPoints: true,
    extractLicenseInformation: true,
    thirdPartyLicense: false,
  }
);
