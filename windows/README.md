# Widget de Colmena para Windows 11

## Piloto

1. Publicá la versión de Colmena que incluye `widget.php` y `api/widget-summary.php` en el servidor interno con HTTPS. Usá la URL habitual de Colmena, incluida la subcarpeta si corresponde. Por ejemplo, `https://intranet.ejemplo.org/colmena/`.
2. Desde una PC con Windows 11 x64 y [Microsoft Edge WebView2 Runtime](https://developer.microsoft.com/microsoft-edge/webview2/), extraé todo el ZIP a una carpeta y abrí `ColmenaWidget.exe`.
3. Ingresá la URL base y conectate a la red interna o VPN si el servidor la requiere. Si aparece **Acceso a…**, ingresá primero las credenciales que te pide el servidor cuando abrís esa URL en el navegador. Después, iniciá sesión con tu cuenta de Colmena.
4. Arrastrá el widget desde el tirador superior. Abrí **Configuración** con el engranaje para elegir el tema **Sistema**, **Claro** u **Oscuro**, ajustar el tamaño o la **Transparencia** (de 0 a 60 %), activar **Siempre visible** o cambiar de servidor. Las preferencias se guardan en esta PC.
5. Los títulos de las secciones abren el detalle en el navegador predeterminado, que tiene su propia sesión.

El widget se actualiza al abrir, cada cinco minutos y al presionar **Actualizar**. Los vencimientos abarcan hoy y los seis días siguientes según la fecha del servidor PHP. InvGate y DevOps muestran el último respaldo local obtenido por los procesos de sincronización de Colmena. Si falla la conexión, el widget conserva el último resumen en memoria y muestra la hora de esa actualización. **Cerrar sesión** cierra la sesión del widget.

## Sincronización de InvGate con el widget abierto

1. Publicá la versión de Colmena que incluye `api/widget-invgate-sync.php` y actualizá el ejecutable de Windows. En `config/config.php`, agregá `'widget_sync_enabled' => true` dentro del bloque `invgate` cuando el cron esté listo.
2. En Ubuntu, agregá esta entrada al `crontab` del usuario que ejecuta PHP para Colmena, normalmente `www-data`. Usá la ruta real del repositorio y de PHP CLI. Ese usuario necesita escribir en SQLite, en su directorio y en `storage/logs/`. Creá `storage/logs/` antes de activar el cron.

   ```cron
   * * * * * cd /ruta/a/colmena && /usr/bin/php database/sync_invgate_tickets.php --widget-worker >> storage/logs/invgate-tickets.log 2>&1
   ```

3. Si ya existe un cron que ejecuta `sync_invgate_tickets.php` sin `--widget-worker`, seguirá actualizando por su cuenta. Para que la actualización automática de tickets dependa del widget, reemplazá solo esa parte de tu cron por la entrada anterior. La sincronización manual usa el mismo bloqueo y, con esta función habilitada, conviene ejecutarla con el mismo usuario de PHP.

Un widget con sesión administradora solicita la sincronización al abrir y cada 15 minutos, incluso minimizado. El cron procesa las solicitudes durante los dos minutos siguientes a su recepción y aplica el intervalo para toda la instalación. Al cerrar la ventana o la sesión cesan los pedidos. El resumen muestra el resultado del último intento y los detalles de errores quedan en el log del servidor.

## Compilar desde el repositorio

Con el [SDK de .NET 10 x64](https://dotnet.microsoft.com/download/dotnet/10.0) y acceso a NuGet, ejecutá desde la raíz del repositorio:

```powershell
.\windows\package-widget.ps1
```

El ZIP queda en `windows/dist/ColmenaWidget-win-x64.zip` e incluye el runtime de .NET. Para usar un SDK portátil, pasá `-DotNet` con la ruta de su `dotnet.exe`.
