import { getContainerName } from '@nextcloud/e2e-test-server'

/**
 * Name of the Docker container running the test server. It is derived from the
 * basename of the project directory, so a git worktree gets a different one
 * than the main checkout — asking the package that named it, instead of
 * hardcoding `…_attendance`, keeps `docker exec` working in both.
 */
export const CONTAINER_NAME = getContainerName()
