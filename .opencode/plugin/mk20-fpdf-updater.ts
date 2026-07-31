import type { Plugin } from "@opencode-ai/plugin"
import type { BunShell } from "@opencode-ai/plugin/shell"
import { promises as fs } from "node:fs"
import path from "node:path"
import os from "node:os"
import process from "node:process"

const PACKAGIST_URL = "https://packagist.org/packages/fpdf-mirror/fpdf.json"
const GITHUB_REPO = "merka20/MK20_custom-certificates"
const ZIP_NAME = "mk20-custom-certificates.zip"
const MAIN_FILE = "mk20-custom-certificates.php"
const README_FILE = "readme.txt"
const MANIFEST_FILE = "mk20-update.json"
const FPDF_DIR = path.join("lib", "fpdf")

const STABLE_RE = /^\d+\.\d+(\.\d+)?$/
const VERSION_RE = /const\s+VERSION\s*=\s*'([^']+)'/
const PLUGIN_VERSION_RE = /Version:\s*(\d+\.\d+(\.\d+)?)/
const STABLE_TAG_RE = /(Stable tag:\s*)(\d+\.\d+(\.\d+)?)/
const CHANGELOG_HEADER_RE = /(== Changelog ==\n)/

export type FpdfUpdateResult =
  | { status: "skip"; reason: string }
  | { status: "uptodate"; installed: string; latest: string }
  | { status: "updated"; installed: string; latest: string; pluginVersion: string; tag: string }

export function isStable(v: string): boolean {
  return STABLE_RE.test(v)
}

export function normalizeVersion(v: string): string {
  const parts = v.split(".")
  while (parts.length < 3) {
    parts.push("0")
  }
  return parts.slice(0, 3).join(".")
}

export function compareVersions(a: string, b: string): number {
  const pa = normalizeVersion(a).split(".").map(Number)
  const pb = normalizeVersion(b).split(".").map(Number)
  for (let i = 0; i < 3; i++) {
    if (pa[i] !== pb[i]) {
      return pa[i] - pb[i]
    }
  }
  return 0
}

export function versionGt(a: string, b: string): boolean {
  return compareVersions(a, b) > 0
}

export function bumpPatch(v: string): string {
  const parts = normalizeVersion(v).split(".").map(Number)
  parts[2] = parts[2] + 1
  return parts.join(".")
}

export function isTrustedDistUrl(url: string): boolean {
  try {
    const u = new URL(url)
    const hosts = new Set(["api.github.com", "github.com", "codeload.github.com"])
    return hosts.has(u.hostname) && u.pathname.includes("FPDF-mirror")
  } catch {
    return false
  }
}

async function pathExists(p: string): Promise<boolean> {
  try {
    await fs.access(p)
    return true
  } catch {
    return false
  }
}

export async function readFpdfVersion(file: string): Promise<string | null> {
  const text = await fs.readFile(file, "utf8")
  const m = text.match(VERSION_RE)
  return m ? m[1] : null
}

export async function readPluginVersion(file: string): Promise<string | null> {
  const text = await fs.readFile(file, "utf8")
  const m = text.match(PLUGIN_VERSION_RE)
  return m ? m[1] : null
}

export async function fetchLatestFpdf(): Promise<{ version: string; distUrl: string } | null> {
  const res = await fetch(PACKAGIST_URL)
  if (!res.ok) {
    return null
  }
  const data: unknown = await res.json()
  const versions = (data as { package?: { versions?: Record<string, { dist?: { url?: string } }> } })?.package?.versions
  if (!versions) {
    return null
  }
  const stable = Object.keys(versions).filter(isStable)
  if (stable.length === 0) {
    return null
  }
  stable.sort(compareVersions)
  const latest = stable[stable.length - 1]
  const distUrl = versions[latest]?.dist?.url
  if (!distUrl || !isTrustedDistUrl(distUrl)) {
    return null
  }
  return { version: latest, distUrl }
}

async function findFpdfDir(extractDir: string): Promise<string | null> {
  const direct = path.join(extractDir, "fpdf", "fpdf.php")
  if (await pathExists(direct)) {
    return path.join(extractDir, "fpdf")
  }
  const entries = await fs.readdir(extractDir, { withFileTypes: true })
  for (const entry of entries) {
    if (!entry.isDirectory()) {
      continue
    }
    const candidate = path.join(extractDir, entry.name, "fpdf", "fpdf.php")
    if (await pathExists(candidate)) {
      return path.join(extractDir, entry.name, "fpdf")
    }
  }
  return null
}

async function downloadTo(url: string, dest: string): Promise<void> {
  const res = await fetch(url)
  if (!res.ok) {
    throw new Error(`Descarga fallida (${res.status}): ${url}`)
  }
  await fs.writeFile(dest, new Uint8Array(await res.arrayBuffer()))
}

function shPath(p: string): string {
  return p.split(path.sep).join("/")
}

function runShell(shell: BunShell) {
  return async (strings: TemplateStringsArray, ...values: unknown[]): Promise<string> => {
    const res = await shell(strings, ...values).text()
    return res.trim()
  }
}

export async function writeManifest(repoRoot: string, pluginVersion: string, fpdfVersion: string, tag: string): Promise<void> {
  const file = path.join(repoRoot, MANIFEST_FILE)
  let manifest: Record<string, string> = {}
  try {
    const existing = JSON.parse(await fs.readFile(file, "utf8"))
    if (existing && typeof existing === "object") {
      manifest = existing
    }
  } catch {
    manifest = {}
  }
  manifest.version = pluginVersion
  manifest.fpdf = fpdfVersion
  manifest.download_url = `https://github.com/${GITHUB_REPO}/releases/download/${tag}/${ZIP_NAME}`
  manifest.changelog = `- FPDF actualizada a ${fpdfVersion}`
  if (!manifest.slug) {
    manifest.slug = "mk20-custom-certificates"
  }
  if (!manifest.requires) {
    manifest.requires = "5.0"
  }
  if (!manifest.tested) {
    manifest.tested = "7.0"
  }
  if (!manifest.requires_php) {
    manifest.requires_php = "7.4"
  }
  await fs.writeFile(file, JSON.stringify(manifest, null, 2) + "\n", "utf8")
}

export async function buildPluginZip(shell: BunShell, repoRoot: string, staging: string, version: string): Promise<string> {
  const pkgDir = path.join(staging, "mk20-custom-certificates")
  await fs.mkdir(pkgDir, { recursive: true })
  const include = [MAIN_FILE, README_FILE, "uninstall.php", MANIFEST_FILE, "includes", "lib", "templates"]
  for (const item of include) {
    const src = path.join(repoRoot, item)
    if (await pathExists(src)) {
      await fs.cp(src, path.join(pkgDir, item), { recursive: true })
    }
  }
  const zipPath = path.join(staging, ZIP_NAME)
  const sh = runShell(shell)
  await sh`tar -a -c -f ${shPath(zipPath)} -C ${shPath(staging)} mk20-custom-certificates`
  return zipPath
}

export async function bumpPluginVersionFiles(repoRoot: string, newVersion: string): Promise<void> {
  const mainFile = path.join(repoRoot, MAIN_FILE)
  const readmeFile = path.join(repoRoot, README_FILE)

  const mainText = await fs.readFile(mainFile, "utf8")
  const mainUpdated = mainText.replace(PLUGIN_VERSION_RE, `Version: ${newVersion}`)
  if (mainUpdated === mainText) {
    throw new Error(`No se encontro la version en ${MAIN_FILE}`)
  }
  await fs.writeFile(mainFile, mainUpdated, "utf8")

  const readmeText = await fs.readFile(readmeFile, "utf8")
  const readmeTagged = readmeText.replace(STABLE_TAG_RE, `$1${newVersion}`)
  if (readmeTagged === readmeText) {
    throw new Error(`No se encontro Stable tag en ${README_FILE}`)
  }
  const changelogEntry = `= ${newVersion} =\n* FPDF actualizada\n\n`
  const readmeUpdated = readmeTagged.replace(CHANGELOG_HEADER_RE, `$1${changelogEntry}`)
  if (readmeUpdated === readmeTagged) {
    throw new Error(`No se encontro la seccion Changelog en ${README_FILE}`)
  }
  await fs.writeFile(readmeFile, readmeUpdated, "utf8")
}

async function appendLog(logPath: string, msg: string): Promise<void> {
  try {
    await fs.mkdir(path.dirname(logPath), { recursive: true })
    await fs.appendFile(logPath, `[${new Date().toISOString()}] ${msg}\n`, "utf8")
  } catch {
    // noop
  }
}

export async function runFpdfUpdate(
  shell: BunShell,
  repoRoot: string,
  logPath: string,
  opts?: { dryRun?: boolean }
): Promise<FpdfUpdateResult> {
  const fpdfFile = path.join(repoRoot, FPDF_DIR, "fpdf.php")

  if (!(await pathExists(fpdfFile))) {
    await appendLog(logPath, "SKIP: no se encontro lib/fpdf/fpdf.php en el workspace")
    return { status: "skip", reason: "workspace sin libreria FPDF" }
  }

  const installed = await readFpdfVersion(fpdfFile)
  if (!installed) {
    await appendLog(logPath, "SKIP: no se pudo leer const VERSION instalada")
    return { status: "skip", reason: "version instalada ilegible" }
  }

  const latest = await fetchLatestFpdf()
  if (!latest) {
    await appendLog(logPath, "SKIP: no se pudo consultar Packagist")
    return { status: "skip", reason: "Packagist no disponible" }
  }

  await appendLog(logPath, `FPDF instalada=${installed} ultima=${latest.version}`)

  if (!versionGt(latest.version, installed)) {
    await appendLog(logPath, "FPDF ya esta al dia, sin cambios")
    return { status: "uptodate", installed, latest: latest.version }
  }

  const dryRun = Boolean(opts?.dryRun)
  const sh = runShell(shell)
  const tmp = await fs.mkdtemp(path.join(os.tmpdir(), "mk20-fpdf-"))
  let backup: string | null = null

  try {
    const zipPath = path.join(tmp, "fpdf-mirror.zip")
    await downloadTo(latest.distUrl, zipPath)
    const extractDir = path.join(tmp, "mirror")
    await fs.mkdir(extractDir, { recursive: true })
    await sh`tar -xf ${shPath(zipPath)} -C ${shPath(extractDir)}`

    const fpdfSrc = await findFpdfDir(extractDir)
    if (!fpdfSrc) {
      throw new Error("No se encontro la carpeta fpdf dentro del mirror")
    }

    const fpdfDir = path.join(repoRoot, FPDF_DIR)
    backup = path.join(tmp, "fpdf-backup")
    await fs.cp(fpdfDir, backup, { recursive: true })
    await fs.rm(fpdfDir, { recursive: true, force: true })
    await fs.cp(fpdfSrc, fpdfDir, { recursive: true })

    const newFpdfText = await fs.readFile(fpdfFile, "utf8")
    if (!/class\s+FPDF\b/.test(newFpdfText)) {
      throw new Error("La libreria reemplazada no contiene la clase FPDF")
    }
    const newInstalled = await readFpdfVersion(fpdfFile)
    if (!newInstalled) {
      throw new Error("La libreria reemplazada no tiene const VERSION")
    }

    try {
      const lint = await sh`php -l ${shPath(fpdfFile)}`
      await appendLog(logPath, `php -l: ${lint}`)
    } catch {
      await appendLog(logPath, "php -l no disponible o lint fallido (no bloqueante)")
    }

    const pluginVersion = await readPluginVersion(path.join(repoRoot, MAIN_FILE))
    if (!pluginVersion) {
      throw new Error(`No se pudo leer la version del plugin en ${MAIN_FILE}`)
    }
    const newPluginVersion = bumpPatch(pluginVersion)
    await bumpPluginVersionFiles(repoRoot, newPluginVersion)

    const tag = `v${newPluginVersion}`
    await writeManifest(repoRoot, newPluginVersion, newInstalled, tag)

    const staging = path.join(tmp, "package")
    const zipPathOut = await buildPluginZip(shell, repoRoot, staging, newPluginVersion)

    await appendLog(logPath, `Zip generado: ${zipPathOut} (${(await fs.stat(zipPathOut)).size} bytes)`)

    if (!dryRun) {
      const notes = `Actualizacion automatica: FPDF ${installed} -> ${newInstalled}`
      await sh`gh release create ${tag} ${shPath(zipPathOut)} --repo ${GITHUB_REPO} --title ${tag} --notes ${notes}`
      await appendLog(logPath, `Release creado: ${tag}`)

      await sh`git add -A`
      await sh`git commit -m ${`[AGENTE] FPDF actualizada a v${newInstalled}`}`
      await sh`git push`
      await appendLog(logPath, `Commit y push completados`)
    } else {
      await appendLog(logPath, `DRY-RUN: se omitirian gh release create ${tag} y git commit/push`)
    }

    await appendLog(logPath, `OK: FPDF ${installed} -> ${newInstalled}, plugin v${newPluginVersion} (${dryRun ? "dry-run" : "aplicado"})`)
    return { status: "updated", installed, latest: newInstalled, pluginVersion: newPluginVersion, tag }
  } catch (err) {
    if (backup && (await pathExists(backup))) {
      const fpdfDir = path.join(repoRoot, FPDF_DIR)
      await fs.rm(fpdfDir, { recursive: true, force: true })
      await fs.cp(backup, fpdfDir, { recursive: true })
      await appendLog(logPath, "Rollback de lib/fpdf ejecutado tras error")
    }
    await appendLog(logPath, `ERROR: ${err instanceof Error ? err.message : String(err)}`)
    throw err
  } finally {
    await fs.rm(tmp, { recursive: true, force: true }).catch(() => {})
  }
}

let running = false

const updater: Plugin = async ({ $, worktree, directory }) => {
  const repoRoot = worktree || directory
  const logFile =
    process.env.MK20_FPDF_UPDATE_LOG || path.join(os.homedir(), ".config", "opencode", "logs", "mk20-fpdf-updater.log")

  return {
    event: async ({ event }) => {
      if (event.type !== "session.created" || running) {
        return
      }
      running = true
      try {
        await runFpdfUpdate($, repoRoot, logFile)
      } catch (err) {
        await appendLog(logFile, `EVENT ERROR: ${err instanceof Error ? err.message : String(err)}`)
      } finally {
        running = false
      }
    },
  }
}

export default updater
