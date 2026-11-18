# Note per Claude

## Versioni
- Ogni aggiornamento dell'app incrementa la versione (semver, es. 1.0.2 → 1.0.3).
- La versione è il tag git `vX.Y.Z` sul commit di `main`: il tag avvia
  `.github/workflows/docker.yml` (immagine `ghcr.io/angelc112/housefunds:X.Y.Z`)
  e `.github/workflows/release.yml` (zip nella release GitHub).
- Dopo il merge in `main`: `git tag vX.Y.Z <commit-main> && git push origin vX.Y.Z`.
