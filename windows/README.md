# Widget de Colmena para Windows 11

## Piloto

1. Publicá la versión de Colmena que incluye `widget.php` y `api/widget-summary.php` en el servidor interno con HTTPS. Usá la URL habitual de Colmena, incluida la subcarpeta si corresponde. Por ejemplo, `https://intranet.ejemplo.org/colmena/`.
2. Desde una PC con Windows 11 x64 y [Microsoft Edge WebView2 Runtime](https://developer.microsoft.com/microsoft-edge/webview2/), extraé todo el ZIP a una carpeta y abrí `ColmenaWidget.exe`.
3. Ingresá la URL base y conectate a la red interna o VPN si el servidor la requiere. Si aparece **Acceso a…**, ingresá primero las credenciales que te pide el servidor cuando abrís esa URL en el navegador. Después, iniciá sesión con tu cuenta de Colmena.
4. Arrastrá la ventana por la barra de título. **Siempre visible** controla si queda encima de otras ventanas. **Servidor…** permite corregir o cambiar la URL. Los títulos de las secciones abren el detalle en el navegador predeterminado, que tiene su propia sesión.

El widget se actualiza al abrir, cada cinco minutos y al presionar **Actualizar**. Los vencimientos abarcan hoy y los seis días siguientes según la fecha del servidor PHP. InvGate y DevOps muestran el último respaldo local obtenido por los procesos de sincronización de Colmena. Si falla la conexión, el widget conserva el último resumen en memoria y muestra la hora de esa actualización. **Cerrar sesión** cierra la sesión del widget.

## Compilar desde el repositorio

Con el [SDK de .NET 10 x64](https://dotnet.microsoft.com/download/dotnet/10.0) y acceso a NuGet, ejecutá desde la raíz del repositorio:

```powershell
.\windows\package-widget.ps1
```

El ZIP queda en `windows/dist/ColmenaWidget-win-x64.zip` e incluye el runtime de .NET. Para usar un SDK portátil, pasá `-DotNet` con la ruta de su `dotnet.exe`.
