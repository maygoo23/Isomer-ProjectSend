# ProjectSend Docker Setup

This repository packages [ProjectSend](https://www.projectsend.org/) with a hardened multi-stage Docker image and a companion MariaDB service.  It lets you spin up a production-ready instance with one command, or build and publish the image to the GitHub Container Registry (GHCR) for wider distribution.

## Highlights

- **Batteries-included image** – PHP 8.2 + Apache, Composer, npm/gulp asset build, Imagick, GD, and the ProjectSend source tree.
- **Zero-touch database provisioning** – the container waits for MariaDB, runs the installer, and upgrades the schema automatically on first boot.
- **Safe logo handling** – huge uploads skip thumbnail generation to avoid PHP memory exhaustion.
- **Persistent volumes** – user uploads, configuration, and database files live under `./projectsend/` so you can back them up independently of the image.
- **Manual GHCR release pipeline** – trigger the provided GitHub Action to build and push a tagged image.

## Quick Start

> Requirements: Docker 24+ with Compose plugin, at least 2 GB RAM, and a free TCP port 8080.

```bash
# Grab the compose bundle
 git clone https://github.com/maygoo23/isomer-projectsend.git
 cd isomer-projectsend

# (Optional) pre-pull the image (Compose will pull automatically if missing)
 docker pull ghcr.io/maygoo23/isomer-projectsend:latest

# (Optional) override defaults before first start
#   export PROJECTSEND_ADMIN_PASSWORD=your-strong-password

# Launch the stack using the published image
 docker compose up -d

# Tail the logs once to confirm everything is healthy
 docker compose logs projectsend --tail=50
```

Browse to [http://localhost:8080](http://localhost:8080) and sign in with the defaults:

| Username | Password              |
|----------|-----------------------|
| `admin`  | `change-this-password` |

Change the admin password immediately from **System Users → Edit** or override `PROJECTSEND_ADMIN_PASSWORD` in Compose before first run.

Stop the stack at any time with:

```bash
docker compose down
```

Your data lives in:

- `projectsend/projectsend/` – configuration, uploads, temporary files.
- `projectsend/database/` – MariaDB datadir.

Back up those folders to capture the full instance state.

## docker-compose.yml Overview

The included Compose file pulls the GHCR image and runs MariaDB alongside it:

```yaml
services:
  projectsend:
    image: ghcr.io/maygoo23/isomer-projectsend:latest
    depends_on:
      projectsend-db:
        condition: service_healthy
    environment:
      PROJECTSEND_DB_HOST: projectsend-db
      PROJECTSEND_DB_NAME: projectsend
      PROJECTSEND_DB_USER: projectsend
      PROJECTSEND_DB_PASSWORD: projectsend
      PROJECTSEND_DB_TABLE_PREFIX: tbl_
      PROJECTSEND_SITE_LANG: en
      PROJECTSEND_SITE_TITLE: ProjectSend
      PROJECTSEND_BASE_URI: http://localhost:8080/
      PROJECTSEND_ADMIN_NAME: ProjectSend Administrator
      PROJECTSEND_ADMIN_USERNAME: admin
      PROJECTSEND_ADMIN_PASSWORD: change-this-password
      PROJECTSEND_ADMIN_EMAIL: admin@example.com
      PROJECTSEND_RUN_DB_UPGRADES: "1"
      PROJECTSEND_DEBUG: "false"
    ports:
      - "8080:80"
    volumes:
      - ./projectsend/projectsend/config:/config
      - ./projectsend/projectsend/upload:/var/www/html/upload
      - ./projectsend/projectsend/temp:/var/www/html/temp
    restart: unless-stopped

  projectsend-db:
    image: mariadb:10.11
    environment:
      MARIADB_DATABASE: projectsend
      MARIADB_USER: projectsend
      MARIADB_PASSWORD: projectsend
      MARIADB_ROOT_PASSWORD: change-this-root-password
    volumes:
      - ./projectsend/database:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 10s
    restart: unless-stopped
```

### Customising the Compose Deployment

- Change the exposed port (`8080:80`) if port 8080 is in use.
- Override the admin credentials (`PROJECTSEND_ADMIN_*`) before the first boot.  Updating after initial install requires editing the database.
- To run behind HTTPS, front this stack with a reverse proxy such as Traefik, nginx, or Caddy.

## GitHub Actions – Manual GHCR Publish

A manual workflow lives at `.github/workflows/publish.yml`.  It:

1. Checks out the repository.
2. Logs in to `ghcr.io` with the built-in `GITHUB_TOKEN`.
3. Builds the Docker image with Buildx.
4. Pushes `ghcr.io/maygoo23/isomer-projectsend:latest` and `ghcr.io/maygoo23/isomer-projectsend:<git-sha>`.

### Set up once

No extra secrets are required—the workflow already requests `packages: write` and uses the default GitHub token.
(Optional) When triggering the workflow you can provide a custom image name; it will be lowercased automatically.

### Run the workflow

Open the **Actions** tab → **Publish to GHCR** → **Run workflow**, choose the desired ref and optional image name, and click **Run**.  The logs will show the pushed tags on completion.

Consumers can then pull with:

```bash
docker pull ghcr.io/maygoo23/isomer-projectsend:latest
```

## Development Tips

- `projectsend/` is ignored by Git.  Take periodic copies for backups (`cp -a projectsend projectsend-backup-YYYYMMDD`).
- The Docker build uses a multi-stage pipeline; no host Composer/npm dependencies are required.
- `.dockerignore` trims the build context to keep builds fast.

## License

ProjectSend itself is GPLv2.  The containerisation files in this repo are distributed under the same license unless noted otherwise.

