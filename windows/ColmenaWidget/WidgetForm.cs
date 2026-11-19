using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

namespace ColmenaWidget;

internal sealed class WidgetForm : Form
{
    private readonly WidgetSettings settings = WidgetSettings.Read();
    private readonly Panel content = new() { Dock = DockStyle.Fill };
    private readonly FlowLayoutPanel connectionPanel = new()
    {
        Dock = DockStyle.Fill, FlowDirection = FlowDirection.TopDown, WrapContents = false, Padding = new(14),
    };
    private readonly Label connectionMessage = new() { AutoSize = true, MaximumSize = new(310, 0) };
    private readonly Button retryButton = new() { AutoSize = true, Text = "Reintentar" };
    private readonly Button serverButton = new() { AutoSize = true, Text = "Servidor…" };
    private readonly TableLayoutPanel serverEditor = new()
    {
        Dock = DockStyle.Top, AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink, ColumnCount = 1, Padding = new(12, 6, 12, 8),
    };
    private readonly TextBox serverUrl = new() { PlaceholderText = "https://colmena.ejemplo.org/", Dock = DockStyle.Fill };
    private readonly Label serverValidation = new() { AutoSize = true, ForeColor = SystemColors.HotTrack, MaximumSize = new(290, 0) };
    private readonly Button cancelServerEdit = new() { Text = "Cancelar", AutoSize = true };
    private readonly ServerAuthenticationPanel authentication = new();
    private readonly Label notice = new() { Dock = DockStyle.Bottom, AutoSize = true, Padding = new(10, 4, 10, 4), Visible = false };
    private readonly System.Windows.Forms.Timer retryTimer = new() { Interval = 5 * 60 * 1000 };
    private readonly System.Windows.Forms.Timer navigationTimer = new() { Interval = 20 * 1000 };
    private Task<CoreWebView2Environment>? environmentTask;
    private WebView2? browser;
    private Uri? server;
    private Uri? appBase;
    private ulong? navigationId;
    private (CoreWebView2BasicAuthenticationRequestedEventArgs Args, CoreWebView2Deferral Deferral)? pendingChallenge;
    private bool authenticationAttempted;

    internal WidgetForm()
    {
        Text = "Colmena Widget";
        AutoScaleMode = AutoScaleMode.Dpi;
        ClientSize = new Size(400, 510);
        MinimumSize = new Size(330, 370);
        StartPosition = FormStartPosition.Manual;
        MaximizeBox = false;
        TopMost = settings.AlwaysOnTop;

        var topBar = new FlowLayoutPanel
        {
            Dock = DockStyle.Top, AutoSize = true, Padding = new Padding(8, 2, 8, 2),
        };
        var pin = new CheckBox { Text = "Siempre visible", AutoSize = true, Checked = TopMost, Margin = new(3, 7, 12, 3) };
        pin.CheckedChanged += (_, _) => TopMost = pin.Checked;
        serverButton.Click += (_, _) => ShowServerEditor();
        topBar.Controls.AddRange([pin, serverButton]);

        retryButton.Click += async (_, _) => await RetryAsync();
        retryTimer.Tick += async (_, _) => await RetryAsync();
        navigationTimer.Tick += (_, _) =>
        {
            EndNavigation();
            browser?.CoreWebView2?.Stop();
            ShowConnectionError("Colmena tardó demasiado en responder. Revisá la conexión y volvé a intentar.");
        };
        authentication.Submitted += (_, _) => SubmitServerCredentials();
        authentication.Canceled += (_, _) => CancelServerChallenge();
        connectionPanel.Controls.AddRange([connectionMessage, retryButton]);
        content.Controls.Add(connectionPanel);
        BuildServerEditor();
        Controls.Add(content);
        Controls.Add(notice);
        Controls.Add(serverEditor);
        Controls.Add(authentication);
        Controls.Add(topBar);

        if (TryParseServer(settings.ServerUrl, out Uri savedServer))
        {
            serverEditor.Visible = false;
            ShowConnectionMessage("Conectando con Colmena…", allowRetry: false);
            Shown += async (_, _) => await LoadServerAsync(savedServer);
        }
        else
        {
            connectionPanel.Visible = false;
            ShowServerEditor();
            Shown += (_, _) => serverUrl.Focus();
        }
    }

    protected override void OnLoad(EventArgs e)
    {
        base.OnLoad(e);
        var screen = Screen.FromPoint(Cursor.Position);
        Rectangle area = screen.WorkingArea;
        var fallback = new Point(Math.Max(area.Left, area.Right - Width - 16), area.Top + 16);
        if (settings.Left is int left && settings.Top is int top)
        {
            var saved = new Point(left, top);
            Screen? savedScreen = Screen.AllScreens.FirstOrDefault(display => display.WorkingArea.Contains(saved));
            if (savedScreen is not null)
            {
                area = savedScreen.WorkingArea;
                Location = new Point(
                    Math.Clamp(left, area.Left, Math.Max(area.Left, area.Right - Width)),
                    Math.Clamp(top, area.Top, Math.Max(area.Top, area.Bottom - Height)));
                return;
            }
        }
        Location = fallback;
    }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        base.OnFormClosing(e);
        if (e.Cancel) return;
        browser = null;
        CompleteServerChallenge(cancel: true);
        retryTimer.Dispose();
        navigationTimer.Dispose();
        Point position = WindowState == FormWindowState.Normal ? Location : RestoreBounds.Location;
        settings.Left = position.X;
        settings.Top = position.Y;
        settings.AlwaysOnTop = TopMost;
        SaveSettings();
    }

    private void BuildServerEditor()
    {
        serverEditor.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        var label = new Label { Text = "URL HTTPS de Colmena", AutoSize = true };
        var buttons = new FlowLayoutPanel { AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink };
        var connect = new Button { Text = "Conectar", AutoSize = true };
        connect.Click += async (_, _) => await ConnectAsync();
        cancelServerEdit.Click += (_, _) => serverEditor.Visible = false;
        serverUrl.KeyDown += async (_, args) =>
        {
            if (args.KeyCode != Keys.Enter) return;
            args.SuppressKeyPress = true;
            await ConnectAsync();
        };
        buttons.Controls.AddRange([connect, cancelServerEdit]);
        serverEditor.Controls.AddRange([label, serverUrl, serverValidation, buttons]);
    }

    private void ShowServerEditor()
    {
        if (pendingChallenge is not null) CancelServerChallenge();
        if (!serverEditor.Visible || !Visible)
        {
            serverUrl.Text = settings.ServerUrl ?? "";
            serverValidation.Text = "";
        }
        cancelServerEdit.Visible = server is not null;
        serverEditor.Visible = true;
        serverUrl.Focus();
        serverUrl.SelectAll();
    }

    private async Task ConnectAsync()
    {
        if (!TryParseServer(serverUrl.Text, out Uri address))
        {
            serverValidation.Text = "Ingresá una URL HTTPS sin usuario, parámetros ni fragmento.";
            serverUrl.Focus();
            return;
        }
        serverEditor.Visible = false;
        await LoadServerAsync(address);
    }

    private static bool TryParseServer(string? value, out Uri serverUri)
    {
        serverUri = null!;
        if (!Uri.TryCreate(value?.Trim(), UriKind.Absolute, out Uri? uri) || uri.Scheme != Uri.UriSchemeHttps
            || uri.Host.Length == 0 || uri.UserInfo.Length != 0 || uri.Query.Length != 0 || uri.Fragment.Length != 0)
            return false;

        serverUri = new UriBuilder(uri) { Path = uri.AbsolutePath.TrimEnd('/') + "/" }.Uri;
        return true;
    }

    private async Task LoadServerAsync(Uri address)
    {
        retryTimer.Stop();
        EndNavigation();
        CompleteServerChallenge(cancel: true);
        authenticationAttempted = false;
        server = address;
        appBase = address;
        if (settings.ServerUrl != address.AbsoluteUri)
        {
            settings.ServerUrl = address.AbsoluteUri;
            SaveSettings();
        }
        ShowConnectionMessage("Conectando con Colmena…", allowRetry: false);
        var previous = browser;
        var next = new WebView2 { Dock = DockStyle.Fill, Visible = false };
        browser = next;
        previous?.Dispose();
        content.Controls.Add(next);

        try
        {
            var pendingEnvironment = environmentTask ??= CoreWebView2Environment.CreateAsync(
                userDataFolder: Path.Combine(WidgetSettings.Folder, "WebView2"));
            var environment = await pendingEnvironment;
            if (!IsCurrentBrowser(next)) return;

            var profile = environment.CreateCoreWebView2ControllerOptions();
            profile.ProfileName = "colmena-" + Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(address.AbsoluteUri)))[..32];
            await next.EnsureCoreWebView2Async(environment, profile);
            if (!IsCurrentBrowser(next)) return;

            CoreWebView2 core = next.CoreWebView2;
            core.Settings.AreHostObjectsAllowed = false;
            core.Settings.IsWebMessageEnabled = false;
            core.Settings.AreDevToolsEnabled = false;
            core.Settings.AreDefaultContextMenusEnabled = false;
            core.Settings.AreBrowserAcceleratorKeysEnabled = false;
            core.Profile.IsPasswordAutosaveEnabled = false;
            core.Profile.IsGeneralAutofillEnabled = false;
            core.FrameNavigationStarting += (_, args) => args.Cancel = true;
            core.PermissionRequested += (_, args) => args.State = CoreWebView2PermissionState.Deny;
            core.DownloadStarting += (_, args) => args.Cancel = true;
            core.BasicAuthenticationRequested += (_, args) => OnServerChallenge(next, args);
            core.NavigationStarting += (_, args) => OnNavigationStarting(next, args);
            core.NavigationCompleted += (_, args) => OnNavigationCompleted(next, args);
            core.NewWindowRequested += (_, args) =>
            {
                args.Handled = true;
                if (IsCurrentBrowser(next) && args.IsUserInitiated) OpenSection(args.Uri);
            };
            core.Navigate(new Uri(address, "widget.php").AbsoluteUri);
        }
        catch (WebView2RuntimeNotFoundException)
        {
            if (IsCurrentBrowser(next)) ShowConnectionMessage("Instalá Microsoft Edge WebView2 Runtime y volvé a intentar.", allowRetry: true);
        }
        catch (Exception error) when (error is COMException or IOException or UnauthorizedAccessException or InvalidOperationException or OperationCanceledException)
        {
            Debug.WriteLine(error);
            if (IsCurrentBrowser(next)) ShowConnectionMessage("No se pudo iniciar la ventana de Colmena.", allowRetry: true);
        }
        finally
        {
            if (environmentTask is { IsCompletedSuccessfully: false, IsCompleted: true }) environmentTask = null;
        }
    }

    private bool IsCurrentBrowser(WebView2 source) => !IsDisposed && !Disposing && ReferenceEquals(browser, source);

    private void OnNavigationStarting(WebView2 source, CoreWebView2NavigationStartingEventArgs args)
    {
        if (!IsCurrentBrowser(source))
        {
            args.Cancel = true;
            return;
        }
        if (Matches(args.Uri, "widget.php") || Matches(args.Uri, "login.php?next=widget"))
        {
            if (navigationId != args.NavigationId)
            {
                navigationTimer.Stop();
                navigationId = args.NavigationId;
                if (pendingChallenge is null) navigationTimer.Start();
            }
            return;
        }
        args.Cancel = true;
        if (args.IsUserInitiated && !args.IsRedirected)
            OpenSection(args.Uri);
        else
        {
            EndNavigation();
            ShowConnectionMessage("El servidor redirigió a una dirección inesperada. Revisá la URL de Colmena.", allowRetry: true);
        }
    }

    private void OnNavigationCompleted(WebView2 source, CoreWebView2NavigationCompletedEventArgs args)
    {
        if (!IsCurrentBrowser(source) || navigationId != args.NavigationId) return;
        EndNavigation();
        if (args.WebErrorStatus == CoreWebView2WebErrorStatus.OperationCanceled) return;
        if (args.IsSuccess && args.HttpStatusCode < 400)
        {
            retryTimer.Stop();
            connectionPanel.Visible = false;
            source.Visible = true;
            if (pendingChallenge is null)
            {
                authenticationAttempted = false;
                authentication.HideChallenge(clearUserName: true);
            }
        }
        else if (args.HttpStatusCode == 404)
        {
            if (appBase == server && server is not null && !server.AbsolutePath.EndsWith("/public/", StringComparison.Ordinal))
            {
                appBase = new Uri(server, "public/");
                source.CoreWebView2.Navigate(new Uri(appBase, "widget.php").AbsoluteUri);
                return;
            }
            ShowConnectionMessage("No se encontró el widget en el servidor. Revisá la URL y la versión publicada de Colmena.", allowRetry: true);
        }
        else if (args.HttpStatusCode is 401 or 403)
        {
            ShowConnectionMessage("El servidor denegó el acceso. Revisá tus credenciales o permisos.", allowRetry: true);
        }
        else
        {
            ShowConnectionError("No se pudo abrir Colmena. Revisá la URL y la conexión a la red interna.");
        }
    }

    private bool Matches(string value, string relative)
    {
        if (appBase is null || !Uri.TryCreate(value, UriKind.Absolute, out Uri? uri)) return false;
        var target = new Uri(appBase, relative);
        return IsSameOrigin(uri, target) && uri.Fragment.Length == 0 && uri.PathAndQuery == target.PathAndQuery;
    }

    private static bool IsSameOrigin(Uri uri, Uri target) => uri.Scheme == Uri.UriSchemeHttps
        && uri.IdnHost == target.IdnHost && uri.Port == target.Port && uri.UserInfo.Length == 0;

    private void OnServerChallenge(WebView2 source, CoreWebView2BasicAuthenticationRequestedEventArgs args)
    {
        if (!IsCurrentBrowser(source) || appBase is null || pendingChallenge is not null
            || !Uri.TryCreate(args.Uri, UriKind.Absolute, out Uri? challenged)
            || !IsSameOrigin(challenged, appBase)
            || !challenged.AbsolutePath.StartsWith(appBase.AbsolutePath, StringComparison.Ordinal)
            || !(args.Challenge.StartsWith("Basic ", StringComparison.OrdinalIgnoreCase)
                || string.Equals(args.Challenge, "Basic", StringComparison.OrdinalIgnoreCase)))
        {
            args.Cancel = true;
            return;
        }

        pendingChallenge = (args, args.GetDeferral());
        retryTimer.Stop();
        navigationTimer.Stop();
        connectionPanel.Visible = false;
        authentication.ShowChallenge(appBase, authenticationAttempted);
    }

    private void SubmitServerCredentials()
    {
        if (pendingChallenge is null) return;
        authenticationAttempted = true;
        if (navigationId is not null)
        {
            ShowConnectionMessage("Verificando el acceso al servidor…", allowRetry: false);
            navigationTimer.Start();
        }
        CompleteServerChallenge(cancel: false);
    }

    private void CancelServerChallenge()
    {
        bool wasNavigating = navigationId is not null;
        EndNavigation();
        CompleteServerChallenge(cancel: true);
        authenticationAttempted = false;
        if (wasNavigating)
        {
            browser?.CoreWebView2?.Stop();
            ShowConnectionMessage("Cancelaste el acceso al servidor.", allowRetry: true);
        }
    }

    private void CompleteServerChallenge(bool cancel)
    {
        var challenge = pendingChallenge;
        pendingChallenge = null;
        try
        {
            if (challenge is null) return;
            var args = challenge.Value.Args;
            args.Cancel = cancel;
            if (!cancel)
            {
                args.Response.UserName = authentication.UserName;
                args.Response.Password = authentication.Password;
            }
        }
        finally
        {
            authentication.HideChallenge(clearUserName: cancel);
            challenge?.Deferral.Complete();
        }
    }

    private void EndNavigation()
    {
        navigationId = null;
        navigationTimer.Stop();
    }

    private void OpenSection(string address)
    {
        if (!Matches(address, "index.php") && !Matches(address, "alerts.php") && !Matches(address, "dashboard.php?panel=focus")
            && !Matches(address, "invgate.php") && !Matches(address, "devops.php")) return;

        try
        {
            Process.Start(new ProcessStartInfo(address) { UseShellExecute = true });
        }
        catch (System.ComponentModel.Win32Exception)
        {
            ShowNotice("No se pudo abrir el navegador predeterminado.");
        }
    }

    private async Task RetryAsync()
    {
        retryTimer.Stop();
        if (server is null || appBase is null) return;
        if (browser?.CoreWebView2 is CoreWebView2 core)
        {
            ShowConnectionMessage("Conectando con Colmena…", allowRetry: false);
            core.Navigate(new Uri(appBase, "widget.php").AbsoluteUri);
        }
        else
        {
            await LoadServerAsync(server);
        }
    }

    private void ShowConnectionMessage(string message, bool allowRetry)
    {
        if (browser is not null) browser.Visible = false;
        connectionMessage.Text = message;
        retryButton.Visible = allowRetry;
        connectionPanel.Visible = true;
        connectionPanel.BringToFront();
    }

    private void ShowConnectionError(string message)
    {
        ShowConnectionMessage(message, allowRetry: true);
        retryTimer.Start();
    }

    private void ShowNotice(string message)
    {
        notice.Text = message;
        notice.Visible = message.Length != 0;
    }

    private void SaveSettings()
    {
        try
        {
            settings.Save();
            ShowNotice("");
        }
        catch (Exception error) when (error is IOException or UnauthorizedAccessException)
        {
            Debug.WriteLine(error);
            ShowNotice("No se pudo guardar la configuración del widget.");
        }
    }
}
