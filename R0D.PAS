{ AK: this is ancient code for Borland Pascal from 1996. Don't use it. It's here just for example. }

{$I-}
var
  SourceFile, TargetFile: Text;
  SourceBytes, TargetBytes: LongInt;
  ch: Char;
Begin
  WriteLn('Text file convert (Remove 0Dh characters). (c) 1996 by Aleksey Kuznetsov');
  if ParamCount < 1 then
   begin
    WriteLn('USAGE: r0d.exe <source_file>');
    Halt;
   end;

  Assign(SourceFile, ParamStr(1));
  Reset(SourceFile);
  if IOResult <> 0 then
   begin
    WriteLn('Can''t open file '+ParamStr(1));
    Halt;
   end;
  Assign(TargetFile, 'target.tmp');
  Rewrite(TargetFile);
  if IOResult <> 0 then
   begin
    WriteLn('Disk Error');
    Halt;
   end;

  WriteLn('Converting '+ParamStr(1) + '....');
  SourceBytes := 0;
  TargetBytes := 0;
  while not Eof(SourceFile) do
   begin
    Read(SourceFile, ch);
    inc(SourceBytes);
    if ch <> #$0d then
     begin
      Write(TargetFile, ch);
      inc(TargetBytes);
     end;
   end;
  Close(SourceFile);
  Close(TargetFile);
  {Erase(SourceFile);}

  Rename(TargetFile, ParamStr(1));
  if IOResult <> 0 then
   begin
    WriteLn('Disk Error.');
    Halt;
   end;
  WriteLn('Converted. Source = ', SourceBytes, ' Target = ', TargetBytes);
End.