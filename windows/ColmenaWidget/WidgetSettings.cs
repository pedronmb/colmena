using System.Text.Json;

namespace ColmenaWidget;

internal sealed class WidgetSettings
{
    internal static readonly string Folder = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "ColmenaWidget");

    private static string SettingsFile => Path.Combine(Folder, "settings.json");

    public string? ServerUrl { get; set; }
    public bool AlwaysOnTop { get; set; } = true;
    public WidgetTheme Theme { get; set; }
    public WidgetSize WindowSize { get; set; }
    public int Transparency { get; set; }
    public int? Left { get; set; }
    public int? Top { get; set; }

    internal static WidgetSettings Read()
    {
        try
        {
            return JsonSerializer.Deserialize<WidgetSettings>(File.ReadAllText(SettingsFile)) ?? new();
        }
        catch (Exception error) when (error is IOException or UnauthorizedAccessException or JsonException)
        {
            return new();
        }
    }

    internal void Save()
    {
        Directory.CreateDirectory(Folder);
        string temporaryFile = Path.Combine(Folder, $"settings-{Guid.NewGuid():N}.tmp");
        try
        {
            File.WriteAllText(temporaryFile, JsonSerializer.Serialize(this));
            File.Move(temporaryFile, SettingsFile, overwrite: true);
        }
        finally
        {
            File.Delete(temporaryFile);
        }
    }
}
