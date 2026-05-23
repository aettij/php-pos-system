# Déploiement gratuit en ligne

## Option 1 : Railway.app (recommandé)

**Gratuit :** 500h/mois + $5 de crédit (suffisant pour PostgreSQL + app)

1. Créez un compte sur https://railway.app
2. Installez le CLI : `curl -fsSL https://railway.app/install.sh | sh`
3. Dans le projet :
   ```bash
   # À la racine du projet
   railway login
   railway init
   ```
4. Ajoutez une base PostgreSQL depuis le dashboard Railway
5. Configurez les variables d'environnement dans Railway :
   ```
   APP_ENV=production
   DB_HOST= (fourni par Railway)
   DB_PORT=5432
   DB_NAME= (fourni par Railway)
   DB_USER= (fourni par Railway)
   DB_PASSWORD= (fourni par Railway)
   SESSION_LIFETIME=7200
   ```
6. Créez un fichier `railway.json` :
   ```json
   {
     "build": {
       "builder": "nixpacks",
       "buildCommand": "composer install || true"
     },
     "deploy": {
       "startCommand": "php -S 0.0.0.0:$PORT index.php",
       "healthcheckPath": "/api/login"
     }
   }
   ```
7. Déployez :
   ```bash
   railway up
   ```
8. Exécutez le script SQL dans la base Railway :
   ```bash
   # Téléchargez le dump et importez
   railway run "psql \$DB_URL < deploy/migration.sql"
   ```
   Ou utilisez le dashboard Railway → PostgreSQL → Connect → psql

---

## Option 2 : Render.com

**Gratuit :** 750h/mois, PostgreSQL expire après 90 jours

1. Compte sur https://render.com
2. Connectez votre dépôt GitHub
3. Créez un **Web Service** :
   - Runtime: `PHP`
   - Build Command: `composer install || true`
   - Start Command: `php -S 0.0.0.0:$PORT index.php`
4. Créez un **PostgreSQL** depuis le dashboard
5. Liez les variables d'environnement dans Render :
   ```
   APP_ENV=production
   DB_HOST= (depuis Render PostgreSQL)
   DB_PORT=5432
   DB_NAME= (depuis Render PostgreSQL)
   DB_USER= (depuis Render PostgreSQL)
   DB_PASSWORD= (depuis Render PostgreSQL)
   RENDER=true
   ```
6. Déployez

---

## Option 3 : Koyeb

**Gratuit :** 1 service web + 1 PostgreSQL

1. Compte sur https://koyeb.com
2. Créez une app → Docker
3. Utilisez l'image PHP officielle : `php:8.3-cli`
4. Command : `php -S 0.0.0.0:$PORT index.php`
5. Ajoutez un PostgreSQL depuis le catalogue Koyeb

---

## Après déploiement

1. **Base de données :** Importez `pos_db.sql` dans votre base distante :
   ```bash
   psql "$DATABASE_URL" < /chemin/vers/pos_db.sql
   ```
   Puis les index :
   ```bash
   psql "$DATABASE_URL" < deploy/migration.sql
   ```

2. **Migration PGSQL :** Si vous utilisez Railway, le schéma est créé via l'import SQL direct. Render nécessite `psql` ou l'import via l'interface web.

3. **Problème SSE :** Sur Railway/Render/Koyeb (plateformes sans véritable persistance de processus), le SSE fonctionne mais peut timeout après 55s (reconnexion automatique par le navigateur). Le polling de secours n'est pas nécessaire — le `EventSource` se reconnecte tout seul.

4. **Logs :** Les logs d'application sont dans `logs/app-YYYY-MM-DD.log`. Sur Railway, utilisez `railway logs` pour voir stdout/stderr.
