#!/usr/bin/env bash
#
# ConsumeServers para Pterodactyl - instalador / actualizador / desinstalador
#
# Normalmente lo invoca el gestor (consumeservers.sh). Uso directo:
#
#   bash scripts/addon-install.sh install
#   bash scripts/addon-install.sh update
#   bash scripts/addon-install.sh uninstall [--force] [--drop-data]
#   bash scripts/addon-install.sh status
#
set -Eeuo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ADDON_VERSION="$(tr -d '[:space:]' < "${SOURCE_DIR}/VERSION" 2>/dev/null || echo 'unknown')"

STATE_DIR="/usr/local/share/consumeservers"
STATE_FILE="${STATE_DIR}/state.env"
BACKUP_DIR="${STATE_DIR}/backups"
CLI_PATH="/usr/local/bin/consumeservers"
MANAGER_SRC_DIR="${SRC_DIR:-/opt/consumeservers}"

TARGET_REL="app/Extensions/ConsumeServers"
PROVIDER_CLASS='Pterodactyl\Extensions\ConsumeServers\Providers\ConsumeServersServiceProvider'
ARTISAN_NAMESPACE="consumeservers:manage"
MIN_PANEL_VERSION="1.11.0"

PANEL_DIR="${PANEL_DIR:-}"
WEB_USER="${WEB_USER:-}"
REPO_URL="${CONSUMESERVERS_REPO:-https://github.com/<YOUR_GITHUB_USER>/ConsumeServers.git}"
ASSUME_YES="no"
DROP_DATA="no"

if [ -t 1 ]; then
    C_RESET=$'\033[0m'; C_RED=$'\033[31m'; C_GREEN=$'\033[32m'
    C_YELLOW=$'\033[33m'; C_BLUE=$'\033[36m'; C_BOLD=$'\033[1m'
else
    C_RESET=''; C_RED=''; C_GREEN=''; C_YELLOW=''; C_BLUE=''; C_BOLD=''
fi

info()  { printf '%s[consumeservers]%s %s\n' "$C_BLUE" "$C_RESET" "$*"; }
ok()    { printf '%s[consumeservers]%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
warn()  { printf '%s[consumeservers]%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
error() { printf '%s[consumeservers]%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; }
die()   { error "$*"; exit 1; }

trap 'error "El script se ha detenido en la línea ${LINENO}."' ERR

require_root() {
    if [ "$(id -u)" -ne 0 ]; then
        die "Este script debe ejecutarse como root (usa: sudo bash scripts/addon-install.sh $1)."
    fi
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "Falta el comando requerido: $1"
}

load_state() {
    # shellcheck disable=SC1090
    [ -f "$STATE_FILE" ] && . "$STATE_FILE" || true
}

save_state() {
    mkdir -p "$STATE_DIR"
    cat > "$STATE_FILE" <<EOF
# Generado por ConsumeServers - no editar a mano
PANEL_DIR="${PANEL_DIR}"
WEB_USER="${WEB_USER}"
REPO_URL="${REPO_URL}"
ADDON_INSTALLED_VERSION="${ADDON_VERSION}"
ADDON_INSTALLED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
EOF
    chmod 600 "$STATE_FILE"
}

detect_panel() {
    if [ -n "$PANEL_DIR" ]; then
        PANEL_DIR="${PANEL_DIR%/}"
        [ -f "${PANEL_DIR}/artisan" ] || die "No se encontró 'artisan' en ${PANEL_DIR}."
        return
    fi

    load_state
    local candidates=("${PANEL_DIR:-}" "/var/www/pterodactyl" "/var/www/panel" "/var/www/html/pterodactyl" "/srv/pterodactyl")
    local dir
    for dir in "${candidates[@]}"; do
        if [ -n "$dir" ] && [ -f "${dir}/artisan" ] && [ -f "${dir}/config/app.php" ]; then
            PANEL_DIR="${dir%/}"
            return
        fi
    done

    die "No se pudo localizar el panel. Ejecuta: sudo PANEL_DIR=/ruta/al/panel bash scripts/addon-install.sh install"
}

detect_panel_soft() {
    load_state

    if [ -n "$PANEL_DIR" ] && [ -f "${PANEL_DIR%/}/artisan" ]; then
        PANEL_DIR="${PANEL_DIR%/}"
        return 0
    fi

    local dir
    for dir in "/var/www/pterodactyl" "/var/www/panel" "/var/www/html/pterodactyl" "/srv/pterodactyl"; do
        if [ -f "${dir}/artisan" ] && [ -f "${dir}/config/app.php" ]; then
            PANEL_DIR="$dir"
            return 0
        fi
    done

    PANEL_DIR=""
    return 1
}

detect_web_user() {
    if [ -n "$WEB_USER" ]; then return; fi
    local owner
    owner="$(stat -c '%U' "${PANEL_DIR}/public" 2>/dev/null || echo '')"
    if [ -n "$owner" ] && [ "$owner" != "root" ] && [ "$owner" != "UNKNOWN" ]; then
        WEB_USER="$owner"
    elif id -u www-data >/dev/null 2>&1; then
        WEB_USER="www-data"
    elif id -u nginx >/dev/null 2>&1; then
        WEB_USER="nginx"
    elif id -u apache >/dev/null 2>&1; then
        WEB_USER="apache"
    else
        WEB_USER="root"
    fi
}

panel_version() {
    local raw
    raw="$(grep -m1 -oE "'version'[[:space:]]*=>[[:space:]]*'[^']+'" "${PANEL_DIR}/config/app.php" 2>/dev/null || true)"
    raw="$(printf '%s' "$raw" | sed -E "s/.*'([^']+)'[[:space:]]*\$/\1/")"
    printf '%s' "${raw:-unknown}"
}

check_panel_version() {
    local version numeric lowest
    version="$(panel_version)"
    numeric="$(printf '%s' "$version" | grep -oE '^[0-9]+(\.[0-9]+){0,2}' || true)"

    if [ -z "$numeric" ]; then
        warn "No se pudo determinar la versión del panel (valor: '${version}'). Continuando."
        return
    fi

    lowest="$(printf '%s\n%s\n' "$numeric" "$MIN_PANEL_VERSION" | sort -V | head -n1)"
    if [ "$lowest" = "$MIN_PANEL_VERSION" ]; then
        info "Panel detectado: v${version} (compatible)."
    else
        die "Se requiere Pterodactyl >= ${MIN_PANEL_VERSION}. Detectado: v${version}."
    fi
}

artisan() {
    if [ "$(id -u)" -eq 0 ] && [ -n "$WEB_USER" ] && [ "$WEB_USER" != "root" ] \
       && command -v runuser >/dev/null 2>&1; then
        ( cd "$PANEL_DIR" && runuser -u "$WEB_USER" -- php artisan "$@" )
    else
        ( cd "$PANEL_DIR" && php artisan "$@" )
    fi
}

backup_file() {
    local file="$1" stamp
    [ -f "$file" ] || return 0
    stamp="$(date -u '+%Y%m%d%H%M%S')"
    mkdir -p "$BACKUP_DIR"
    cp -p "$file" "${BACKUP_DIR}/$(basename "$file").${stamp}.bak"
}

fix_permissions() {
    local path
    for path in "${PANEL_DIR}/${TARGET_REL}" \
                "${PANEL_DIR}/storage" "${PANEL_DIR}/bootstrap/cache"; do
        [ -e "$path" ] && chown -R "${WEB_USER}:${WEB_USER}" "$path" 2>/dev/null || true
    done
    find "${PANEL_DIR}/${TARGET_REL}" -type d -exec chmod 755 {} + 2>/dev/null || true
    find "${PANEL_DIR}/${TARGET_REL}" -type f -exec chmod 644 {} + 2>/dev/null || true
}

clear_caches() {
    info "Limpiando cachés del panel…"
    artisan config:clear >/dev/null 2>&1 || warn "config:clear falló."
    artisan route:clear  >/dev/null 2>&1 || warn "route:clear falló."
    artisan view:clear   >/dev/null 2>&1 || warn "view:clear falló."
    artisan cache:clear  >/dev/null 2>&1 || true
}

sync_source_copy() {
    mkdir -p "$STATE_DIR"
    if [ "$SOURCE_DIR" != "${STATE_DIR}/source" ]; then
        rm -rf "${STATE_DIR}/source.tmp"
        mkdir -p "${STATE_DIR}/source.tmp"
        ( cd "$SOURCE_DIR" && tar --exclude='./.git' -cf - . ) | ( cd "${STATE_DIR}/source.tmp" && tar -xf - )
        rm -rf "${STATE_DIR}/source"
        mv "${STATE_DIR}/source.tmp" "${STATE_DIR}/source"
    fi
    chmod 755 "${STATE_DIR}/source/scripts/addon-install.sh" 2>/dev/null || true
    chmod 755 "${STATE_DIR}/source/consumeservers.sh" 2>/dev/null || true
    chmod 755 "${STATE_DIR}/source/install.sh" 2>/dev/null || true
    chmod 755 "${STATE_DIR}/source/bin/consumeservers" 2>/dev/null || true
}

app_url() {
    grep -m1 -E '^APP_URL=' "${PANEL_DIR}/.env" 2>/dev/null \
        | sed -E 's/^APP_URL=//; s/^"//; s/"$//; s#/$##' || true
}

provider_registered() {
    grep -rq 'ConsumeServersServiceProvider' \
        "${PANEL_DIR}/config/app.php" "${PANEL_DIR}/bootstrap/providers.php" 2>/dev/null
}

panel_patched() {
    grep -rq 'consumeservers:begin' \
        "${PANEL_DIR}/resources/views/layouts/admin.blade.php" 2>/dev/null
}

# ---------------------------------------------------------------------------
# Instalación
# ---------------------------------------------------------------------------

do_install() {
    require_root install
    require_cmd php
    require_cmd tar
    detect_panel
    detect_web_user
    check_panel_version

    [ -d "${SOURCE_DIR}/src" ] || die "No se encuentra ${SOURCE_DIR}/src"
    [ -f "${SOURCE_DIR}/tools/register-provider.php" ] || die "Falta tools/register-provider.php en el repositorio."
    [ -f "${SOURCE_DIR}/tools/patch-panel.php" ] || die "Falta tools/patch-panel.php en el repositorio."

    info "Panel:          ${PANEL_DIR}"
    info "Usuario web:    ${WEB_USER}"
    info "Versión addon:  ${ADDON_VERSION}"

    sync_source_copy
    save_state

    info "Copiando la extensión a ${TARGET_REL}…"
    rm -rf "${PANEL_DIR}/${TARGET_REL}"
    mkdir -p "${PANEL_DIR}/${TARGET_REL}"
    ( cd "${STATE_DIR}/source/src" && tar -cf - . ) \
        | ( cd "${PANEL_DIR}/${TARGET_REL}" && tar -xf - )
    printf '%s\n' "$ADDON_VERSION" > "${PANEL_DIR}/${TARGET_REL}/VERSION"

    # El namespace Pterodactyl\Extensions\* ya lo cubre el autoload PSR-4 del
    # panel (app/ => Pterodactyl\), así que no hay que tocar composer.json.
    info "Registrando el service provider…"
    backup_file "${PANEL_DIR}/config/app.php"
    backup_file "${PANEL_DIR}/bootstrap/providers.php"
    php "${STATE_DIR}/source/tools/register-provider.php" register "$PANEL_DIR" \
        || die "No se pudo registrar el provider. Añádelo manualmente: ${PROVIDER_CLASS}::class,"

    info "Integrando el enlace en el menú de administración…"
    backup_file "${PANEL_DIR}/resources/views/layouts/admin.blade.php"

    # Se retira primero el parche existente (si lo hay) y se vuelve a aplicar
    # entero, para que correcciones futuras lleguen también a paneles ya
    # instalados con una versión anterior.
    php "${STATE_DIR}/source/tools/patch-panel.php" unpatch "$PANEL_DIR" >/dev/null 2>&1 || true
    php "${STATE_DIR}/source/tools/patch-panel.php" patch "$PANEL_DIR" \
        || warn "El parche del menú no se pudo aplicar; la extensión funciona, pero tendrás que entrar por URL (el middleware de respaldo debería añadir el enlace igualmente)."

    clear_caches

    info "Ejecutando migraciones…"
    artisan migrate --force || die "Las migraciones fallaron. Revisa la conexión a la base de datos."

    install_cli
    fix_permissions
    clear_caches

    local url; url="$(app_url)"
    ok "ConsumeServers v${ADDON_VERSION} instalado correctamente."
    printf '\n  %sAdministración:%s  %s/admin/extensions/consumeservers\n\n' "$C_BOLD" "$C_RESET" "${url:-https://tu-panel}"
}

install_cli() {
    info "Instalando el comando 'consumeservers'…"
    if [ -f "${STATE_DIR}/source/bin/consumeservers" ]; then
        install -m 755 "${STATE_DIR}/source/bin/consumeservers" "$CLI_PATH"
    elif [ -f "${STATE_DIR}/source/consumeservers.sh" ]; then
        install -m 755 "${STATE_DIR}/source/consumeservers.sh" "$CLI_PATH"
    else
        warn "No se encontró el ejecutable del CLI; se omite."
    fi
}

# ---------------------------------------------------------------------------
# Actualización
# ---------------------------------------------------------------------------

do_update() {
    require_root update
    require_cmd git
    load_state
    detect_panel
    detect_web_user

    if [ -f "${MANAGER_SRC_DIR}/consumeservers.sh" ]; then
        info "Delegando la actualización en el gestor (${MANAGER_SRC_DIR}/consumeservers.sh)…"
        PANEL_DIR="$PANEL_DIR" WEB_USER="$WEB_USER" \
            exec bash "${MANAGER_SRC_DIR}/consumeservers.sh" update
    fi

    [ -n "$REPO_URL" ] || die "No se conoce la URL del repositorio. Usa: sudo CONSUMESERVERS_REPO=<url> consumeservers update"

    local tmp
    tmp="$(mktemp -d /tmp/consumeservers-update.XXXXXX)"
    info "Descargando la última versión desde ${REPO_URL}…"
    if ! git clone --depth 1 "$REPO_URL" "${tmp}/repo" >/dev/null 2>&1; then
        rm -rf "$tmp"
        die "No se pudo clonar ${REPO_URL}. Comprueba la URL y la conectividad."
    fi

    local new_version
    new_version="$(tr -d '[:space:]' < "${tmp}/repo/VERSION" 2>/dev/null || echo 'unknown')"
    info "Versión instalada: ${ADDON_INSTALLED_VERSION:-desconocida} → nueva: ${new_version}"

    if ! PANEL_DIR="$PANEL_DIR" WEB_USER="$WEB_USER" CONSUMESERVERS_REPO="$REPO_URL" \
            bash "${tmp}/repo/scripts/addon-install.sh" install; then
        rm -rf "$tmp"
        die "La actualización falló."
    fi
    rm -rf "$tmp"
    ok "Actualización completada."
}

# ---------------------------------------------------------------------------
# Desinstalación
# ---------------------------------------------------------------------------

do_uninstall() {
    require_root uninstall
    load_state
    detect_panel
    detect_web_user

    case "$SOURCE_DIR" in
        "${STATE_DIR}"*)
            local tmp
            tmp="$(mktemp -d /tmp/consumeservers-uninstall.XXXXXX)"
            ( cd "${STATE_DIR}/source" && tar -cf - . ) | ( cd "$tmp" && tar -xf - )
            local extra=()
            [ "$ASSUME_YES" = "yes" ] && extra+=(--force)
            [ "$DROP_DATA" = "yes" ] && extra+=(--drop-data)
            PANEL_DIR="$PANEL_DIR" WEB_USER="$WEB_USER" CONSUMESERVERS_SELFCOPY=1 \
                exec bash "${tmp}/scripts/addon-install.sh" uninstall "${extra[@]}"
            ;;
    esac

    if [ "$ASSUME_YES" != "yes" ]; then
        if [ "$DROP_DATA" = "yes" ]; then
            printf '%s' "Esto eliminará la extensión Y sus tablas con todos sus datos. Escribe 'si' para continuar: "
        else
            printf '%s' "Esto eliminará los archivos de la extensión (las tablas se conservan). Escribe 'si' para continuar: "
        fi
        local answer=''
        read -r answer || true
        case "$answer" in
            si|SI|Si|sí|SÍ|yes|y) ;;
            *) die "Desinstalación cancelada." ;;
        esac
    fi

    if [ "$DROP_DATA" = "yes" ]; then
        if [ -d "${PANEL_DIR}/${TARGET_REL}" ]; then
            info "Eliminando las tablas de ConsumeServers…"
            artisan "$ARTISAN_NAMESPACE" purge --force \
                || warn "La purga devolvió un error; se continuará con los archivos."
        else
            warn "La extensión no está en el panel; no se puede purgar la base de datos."
        fi
    fi

    info "Revirtiendo el parche del menú…"
    php "${SOURCE_DIR}/tools/patch-panel.php" unpatch "$PANEL_DIR" \
        || warn "No se pudo revertir el parche; revisa el bloque 'consumeservers:begin' en resources/views/layouts/admin.blade.php."

    info "Eliminando el service provider…"
    php "${SOURCE_DIR}/tools/register-provider.php" unregister "$PANEL_DIR" \
        || warn "No se pudo desregistrar el provider; revísalo a mano."

    info "Eliminando ${PANEL_DIR}/${TARGET_REL}…"
    rm -rf "${PANEL_DIR}/${TARGET_REL}"

    clear_caches

    info "Eliminando el comando y los datos del instalador…"
    rm -f "$CLI_PATH"
    rm -rf "$STATE_DIR"

    ok "ConsumeServers eliminado por completo. El panel queda en su estado original."
}

# ---------------------------------------------------------------------------
# Estado
# ---------------------------------------------------------------------------

do_status() {
    load_state
    detect_panel_soft || true
    if [ -n "$PANEL_DIR" ]; then
        detect_web_user || true
    fi

    printf '%sConsumeServers%s\n' "$C_BOLD" "$C_RESET"
    printf '  Versión del paquete : %s\n' "$ADDON_VERSION"
    printf '  Versión instalada   : %s\n' "${ADDON_INSTALLED_VERSION:-no instalada}"
    printf '  Panel               : %s (v%s)\n' "${PANEL_DIR:-no detectado}" "$(panel_version)"
    printf '  Usuario web         : %s\n' "${WEB_USER:-desconocido}"
    printf '  Extensión           : %s\n' "$([ -d "${PANEL_DIR}/${TARGET_REL}" ] && echo presente || echo ausente)"
    printf '  Provider registrado : %s\n' "$(provider_registered && echo sí || echo no)"
    printf '  Parche del menú     : %s\n' "$(panel_patched && echo aplicado || echo 'no aplicado')"
    printf '  Repositorio         : %s\n' "${REPO_URL:-no configurado}"
    printf '  CLI                 : %s\n' "$([ -x "$CLI_PATH" ] && echo "$CLI_PATH" || echo 'no instalado')"

    if [ -d "${PANEL_DIR}/${TARGET_REL}" ]; then
        printf '\n'
        artisan "$ARTISAN_NAMESPACE" info || warn "No se pudo consultar el estado en el panel."
    fi
}

usage() {
    cat <<EOF
${C_BOLD}ConsumeServers v${ADDON_VERSION}${C_RESET} - instalador para Pterodactyl

Uso: bash scripts/addon-install.sh <comando> [opciones]

Comandos:
  install       Instala o reinstala la extensión en el panel
  update        Descarga la última versión del repositorio y la aplica
  uninstall     Elimina la extensión (añade --drop-data para borrar las tablas)
  status        Muestra el estado de la instalación
  version       Muestra la versión del paquete
  help          Muestra esta ayuda

Opciones:
  --force, -y     No pedir confirmación (uninstall)
  --drop-data     Borra las tablas de la extensión durante uninstall

Variables de entorno:
  PANEL_DIR           Ruta del panel (por defecto se detecta automáticamente)
  WEB_USER            Usuario del servidor web (por defecto se detecta)
  CONSUMESERVERS_REPO URL del repositorio git para 'update'
EOF
}

main() {
    local cmd="${1:-help}"
    shift || true
    local arg
    for arg in "$@"; do
        case "$arg" in
            --force|-y|--yes) ASSUME_YES="yes" ;;
            --drop-data)      DROP_DATA="yes" ;;
            *) warn "Opción desconocida ignorada: ${arg}" ;;
        esac
    done

    case "$cmd" in
        install)            do_install ;;
        update|upgrade)     do_update ;;
        uninstall|remove)   do_uninstall ;;
        status)             do_status ;;
        version|--version)  printf '%s\n' "$ADDON_VERSION" ;;
        help|--help|-h)     usage ;;
        *) usage; die "Comando desconocido: ${cmd}" ;;
    esac
}

main "$@"
