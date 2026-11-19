namespace ColmenaWidget;

internal sealed class ServerAuthenticationPanel : TableLayoutPanel
{
    private readonly Label heading = new() { AutoSize = true, MaximumSize = new(300, 0) };
    private readonly Label explanation = new() { AutoSize = true, MaximumSize = new(290, 0) };
    private readonly TextBox userName = new() { Dock = DockStyle.Fill, AccessibleName = "Usuario del servidor" };
    private readonly TextBox password = new()
    {
        Dock = DockStyle.Fill, UseSystemPasswordChar = true, AccessibleName = "Contraseña del servidor",
    };

    internal event EventHandler? Submitted;
    internal event EventHandler? Canceled;

    internal string UserName => userName.Text;
    internal string Password => password.Text;

    internal ServerAuthenticationPanel()
    {
        Dock = DockStyle.Top;
        AutoSize = true;
        AutoSizeMode = AutoSizeMode.GrowAndShrink;
        ColumnCount = 1;
        ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        Padding = new Padding(12, 6, 12, 8);
        Visible = false;

        var buttons = new FlowLayoutPanel { AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink };
        var confirm = new Button { Text = "Ingresar", AutoSize = true };
        var cancel = new Button { Text = "Cancelar", AutoSize = true };
        confirm.Click += (_, _) => Submit();
        cancel.Click += (_, _) => Canceled?.Invoke(this, EventArgs.Empty);
        password.KeyDown += (_, args) =>
        {
            if (args.KeyCode != Keys.Enter) return;
            args.SuppressKeyPress = true;
            Submit();
        };
        buttons.Controls.AddRange([confirm, cancel]);

        Controls.AddRange([
            heading, explanation,
            new Label { Text = "Usuario del servidor", AutoSize = true }, userName,
            new Label { Text = "Contraseña del servidor", AutoSize = true }, password,
            buttons,
        ]);
    }

    internal void ShowChallenge(Uri server, bool retry)
    {
        heading.Text = $"Acceso a {server.Authority}";
        explanation.Text = retry
            ? "El servidor volvió a pedir las credenciales. Revisalas y volvé a intentar."
            : "Ingresá las credenciales de acceso al servidor. Después se abrirá Colmena.";
        Visible = true;
        if (retry && userName.TextLength > 0) password.Focus();
        else userName.Focus();
    }

    internal void HideChallenge(bool clearUserName)
    {
        password.Clear();
        if (clearUserName) userName.Clear();
        Visible = false;
    }

    private void Submit()
    {
        if (string.IsNullOrWhiteSpace(UserName) || password.TextLength == 0)
        {
            explanation.Text = "Ingresá el usuario y la contraseña del servidor.";
            if (string.IsNullOrWhiteSpace(UserName)) userName.Focus();
            else password.Focus();
            return;
        }
        Submitted?.Invoke(this, EventArgs.Empty);
    }
}
