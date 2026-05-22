Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
batchFile = """" & fso.BuildPath(scriptDir, "start-websocket.bat") & """"

shell.Run batchFile, 0, False

