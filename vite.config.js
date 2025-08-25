import { createAppConfig } from "@nextcloud/vite-config";
import { join, resolve } from "path";

export default createAppConfig(
  {
    main: resolve(join("src", "main.js")),
    dashboardVue: resolve(join("src", "dashboardVue.js")),
  },
  {
    createEmptyCSSEntryPoints: true,
    extractLicenseInformation: true,
    thirdPartyLicense: false,
  }
);
