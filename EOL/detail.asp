<%
Option Explicit
Response.CodePage = 932
Response.Charset  = "shift_jis"

Dim product, kubun
product = Trim(Request("product"))
kubun   = Trim(Request("kubun"))

'=== ここで直接 Access に接続 ===
Dim CN
Set CN = Server.CreateObject("ADODB.Connection")
CN.Open "Provider=Microsoft.Jet.OLEDB.4.0;Data Source=\\172.28.0.11\Webapp\SECTION\Shizai\includes\EndOfLife.mdb;Persist Security Info=False;"

If Request.ServerVariables("REQUEST_METHOD") = "POST" Then
  Dim SQL, CMD
  SQL = "INSERT INTO 終売案内詳細 (製品番号, 区分, 担当者, 期限, コメント, 詳細, ステータス, 登録日, 更新日) " & _
        "VALUES (?,?,?,?,?,?,?, NOW(), NOW())"
  Set CMD = Server.CreateObject("ADODB.Command")
  Set CMD.ActiveConnection = CN
  CMD.CommandText = SQL
  CMD.CommandType = 1
  CMD.Parameters.Append CMD.CreateParameter("p1",200,1,60,product)
  CMD.Parameters.Append CMD.CreateParameter("p2",200,1,60,kubun)
  CMD.Parameters.Append CMD.CreateParameter("p3",200,1,60,Request.Form("tanto"))

  Dim dt : dt = Request.Form("limit")
  If dt = "" Then
    CMD.Parameters.Append CMD.CreateParameter("p4",7,1,,Null) 'adDate
  Else
    CMD.Parameters.Append CMD.CreateParameter("p4",7,1,,dt)
  End If

  CMD.Parameters.Append CMD.CreateParameter("p5",201,1,4000,Request.Form("comment")) 'adLongVarChar
  CMD.Parameters.Append CMD.CreateParameter("p6",201,1,8000,Request.Form("detail"))
  CMD.Parameters.Append CMD.CreateParameter("p7",200,1,20,Request.Form("status"))
  CMD.Execute

  Response.Redirect "detail.asp?product=" & Server.URLEncode(product) & "&kubun=" & Server.URLEncode(kubun)
End If
%>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=shift_jis" />
<title>終売案内 詳細</title>
<style>
  body{font-family:Meiryo,MS UI Gothic;margin:16px}
  label{display:block;margin-top:6px;font-size:13px}
  input[type=text], input[type=date], select, textarea{width:420px;max-width:100%}
  textarea{height:100px}
  table{border-collapse:collapse;width:100%;margin-top:16px}
  th,td{border:1px solid #ddd;padding:6px 8px;font-size:12px}
  th{background:#f3f4f6}
  .badge{display:inline-block;padding:2px 6px;border-radius:10px;color:#fff;font-weight:bold;font-size:12px}
</style>
</head>
<body>
<h2>詳細登録：<%=Server.HTMLEncode(product)%> ／ <%=Server.HTMLEncode(kubun)%></h2>
<form method="post" action="detail.asp?product=<%=Server.URLEncode(product)%>&kubun=<%=Server.URLEncode(kubun)%>">
  <label>担当者<input type="text" name="tanto" /></label>
  <label>期限<input type="date" name="limit" /></label>
  <label>コメント<input type="text" name="comment" /></label>
  <label>詳細<textarea name="detail"></textarea></label>
  <label>ステータス
    <select name="status">
      <option value="未着手">未着手</option>
      <option value="着手">着手</option>
      <option value="完了">完了</option>
      <option value="保留">保留</option>
      <option value="問い合わせ">問い合わせ</option>
    </select>
  </label>
  <div style="margin-top:8px">
    <button type="submit">登録</button> <a href="index.asp">一覧へ戻る</a>
  </div>
</form>

<%
' 履歴表示
Dim RS2, CMD2, SQL2

Function StatusColor(s)
  Select Case s
    Case "完了": StatusColor = "#16a34a"
    Case "着手": StatusColor = "#2563eb"
    Case "保留": StatusColor = "#f59e0b"
    Case "問い合わせ": StatusColor = "#a855f7"
    Case Else: StatusColor = "#9ca3af"
  End Select
End Function

SQL2 = "SELECT ID, 担当者, 期限, コメント, 詳細, ステータス, 登録日, 更新日 " & _
       "FROM 終売案内詳細 WHERE 製品番号=? AND 区分=? " & _
       "ORDER BY 更新日 DESC, ID DESC"
Set CMD2 = Server.CreateObject("ADODB.Command")
Set CMD2.ActiveConnection = CN
CMD2.CommandText = SQL2
CMD2.Parameters.Append CMD2.CreateParameter("p1",200,1,60,product)
CMD2.Parameters.Append CMD2.CreateParameter("p2",200,1,60,kubun)
Set RS2 = CMD2.Execute()
%>
<table>
  <tr>
    <th style="width:60px">ID</th>
    <th style="width:90px">担当者</th>
    <th style="width:110px">期限</th>
    <th style="width:120px">ステータス</th>
    <th>コメント</th>
    <th>詳細</th>
    <th style="width:130px">更新日</th>
  </tr>
<%
Do Until RS2.EOF
  Dim st, col
  st  = RS2("ステータス") & "": col = StatusColor(st)
%>
  <tr>
    <td><%=RS2("ID")%></td>
    <td><%=Server.HTMLEncode(RS2("担当者") & "")%></td>
    <td><%=RS2("期限")%></td>
    <td><span class="badge" style="background:<%=col%>"><%=st%></span></td>
    <td><%=Server.HTMLEncode(RS2("コメント") & "")%></td>
    <td><%=Server.HTMLEncode(RS2("詳細") & "")%></td>
    <td><%=RS2("更新日")%></td>
  </tr>
<%
  RS2.MoveNext
Loop
RS2.Close: Set RS2 = Nothing
CN.Close: Set CN = Nothing
%>
</table>
</body>
</html>
