# Widget de Colmena para Windows 11

## Piloto

1. Publicá la versión de Colmena que incluye `widget.php` y `api/widget-summary.php` en el servidor interno con HTTPS. Usá la URL habitual de Colmena, incluida la subcarpeta si corresponde. Por ejemplo, `https://intranet.ejemplo.org/colmena/`.
2. Desde una PC con Windows 11 x64 y [Microsoft Edge WebView2 Runtime](https://developer.microsoft.com/microsoft-edge/webview2/), extraé todo el ZIP a una carpeta y abrí `ColmenaWidget.exe`.
3. Ingresá la URL base y conectate a la red interna o VPN si el servidor la requiere. Si aparece **Acceso a…**, ingresá primero las credenciales que te pide el servidor cuando abrís esa URL en el navegador. Después, iniciá sesión con tu cuenta de Colmena.
4. Arrastrá el widget desde el tirador superior o cambiá su tamaño arrastrando un borde o una esquina. En **Configuración** también podés elegir un tamaño, cambiar el tema o la **Transparencia** (de 0 a 60 %), activar **Siempre visible** y cambiar de servidor. El tamaño y las demás preferencias se guardan en esta PC.
5. Hacé clic en **InvGate** para ver hasta cinco tickets abiertos con la actividad más reciente. **Ver los tickets por persona** muestra las fichas marcadas como **Equipo directo** y sus prioridades de Colmena. **Temas** muestra los temas activos y las alertas próximas. Los enlaces **Abrir en Colmena** abren el navegador predeterminado.

El widget se actualiza al abrir, cada cinco minutos y al presionar **Actualizar**. Los vencimientos abarcan hoy y los seis días siguientes según la fecha del servidor PHP. InvGate muestra los datos de la última sincronización. Si falla la conexión, el widget conserva el último resumen en memoria y muestra la hora de esa actualización. **Cerrar sesión** cierra la sesión del widget.

## Sincronización de InvGate con el widget abierto

1. Publicá los cambios de Colmena por Git. Si ya usás el widget con sincronización automática, podés seguir usando el mismo ejecutable. En `config/config.php`, dejá `'widget_sync_enabled' => true` dentro del bloque `invgate`.
2. El usuario que ejecuta PHP en el servidor, normalmente `www-data`, necesita acceso a InvGate y permisos de escritura en SQLite, su directorio y el directorio privado de estado (`database/.app.sqlite.invgate-sync` si usás la ruta por defecto). Si una prueba anterior se ejecutó con otro usuario, verificá quién es dueño de ese directorio y sus archivos.
3. Retirá del `crontab` la entrada antigua con `--widget-worker`. Si tenés otros trabajos de InvGate programados aparte, siguen siendo independientes.

El widget con sesión administradora llama al endpoint de Colmena al abrir y cada 15 minutos, incluso minimizado. El PHP del servidor ejecuta la misma sincronización que el script manual y comparte su bloqueo. Una ejecución manual reciente también cuenta para el intervalo. Al cerrar la ventana o la sesión cesan los pedidos nuevos.

Probalo desde **InvGate → Sincronizar InvGate** y mirá **Último intento de InvGate**. En PHP-FPM, el widget recibe la confirmación al empezar y consulta el resultado mientras el servidor trabaja. En otros entornos PHP, la respuesta llega al terminar. Los errores del endpoint quedan en el log de PHP. [Finalización de solicitudes en PHP-FPM](https://www.php.net/manual/es/function.fastcgi-finish-request.php)

Para probar la sincronización desde la consola, usá el mismo usuario de PHP. Por ejemplo, ejecutá `sudo -u www-data /usr/bin/php database/sync_invgate_tickets.php` desde la raíz de Colmena.

La lista reciente usa la última actualización del ticket y los comentarios guardados por `database/sync_invgate_comments.php`, que es una sincronización independiente.

## Compilar desde el repositorio

Con el [SDK de .NET 10 x64](https://dotnet.microsoft.com/download/dotnet/10.0) y acceso a NuGet, ejecutá desde la raíz del repositorio:

```powershell
.\windows\package-widget.ps1
```

El ZIP queda en `windows/dist/ColmenaWidget-win-x64.zip` e incluye el runtime de .NET. Para usar un SDK portátil, pasá `-DotNet` con la ruta de su `dotnet.exe`.
