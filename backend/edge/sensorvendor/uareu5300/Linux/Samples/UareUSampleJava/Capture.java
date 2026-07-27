import java.awt.Dimension;
import java.awt.event.ActionEvent;
import java.awt.event.ActionListener;
import java.awt.GridLayout;
import java.awt.Component;

import javax.swing.Box;
import javax.swing.BoxLayout;
import javax.swing.JButton;
import javax.swing.JDialog;
import javax.swing.JPanel;
import javax.swing.JCheckBox;
import javax.swing.JRadioButton;
import javax.swing.BorderFactory;
import javax.swing.ButtonGroup;

import com.digitalpersona.uareu.*;

public class Capture 
	extends JPanel
	implements ActionListener
{
	private static final long serialVersionUID = 2;
	private static final String ACT_BACK = "back";
	private static final String ACT_PAD = "PAD";
	private static final String ACT_OPM_EXC = "Exclusive";
	private static final String ACT_OPM_COOP = "Cooperative";
	private static final String ACT_IMGP_DEF = "Default";
	private static final String ACT_IMGP_PIV = "PIV";
	private static final String ACT_IMGP_ENH = "Enhanced";
	private static final String ACT_IMGP_ENH2 = "Enhanced 2";

	private JDialog       m_dlgParent;
	private CaptureThread m_capture;
	private Reader        m_reader;
	private ImagePanel    m_image;
	private boolean       m_bStreaming;

	private JCheckBox m_CheckPAD;
	private JPanel m_ParentPanel;
	private JPanel m_SubPanel;
	private JPanel m_IMGProcPanel;
	private JPanel m_OpenModePanel;
	private JButton m_btnBack;
	public static boolean ImageProcessingChange = false;
	private ButtonGroup m_OPModeGroup;
	private ButtonGroup m_IMGProcGroup;
	private String[] OPModeAttr = { "Cooperative", 
									"Exclusive" }; 
	private String[] OPModeProp = { "COOPERATIVE", 
									"EXCLUSIVE" };
	private String[] IMGProcAttr = { "Default", 
									"PIV", 
									"Enhanced", 
									"Enhanced 2" }; 
	private String[] IMGProcProp = { "IMG_PROC_DEFAULT", 
									"IMG_PROC_PIV", 
									"IMG_PROC_ENHANCED", 
									"IMG_PROC_ENHANCED_2"};
	private int IMGProcIndex = 0;

	public void addRadioButton(JPanel panel, ButtonGroup group, int index, String name) {
		boolean selected = false;
		if ((panel.equals(m_OpenModePanel) && UareUSampleJava.OpeningModeFlag.ordinal() == index) || 
			(panel.equals(m_IMGProcPanel) && Reader.ImageProcessing.IMG_PROC_DEFAULT.ordinal() == index))
			selected = true;
		JRadioButton button = new JRadioButton(name, selected);
		button.setActionCommand(name);
		group.add(button);
		panel.add(button);

		if (panel.equals(m_IMGProcPanel) && UareUSampleJava.OpeningModeFlag.equals(Reader.Priority.COOPERATIVE)) {
			m_IMGProcPanel.setEnabled(false);
			button.setEnabled(false);
		}

		if (m_bStreaming && panel.equals(m_OpenModePanel)) {
			m_OpenModePanel.setEnabled(false);
			button.setEnabled(false);
		}

		button.addActionListener(this);
	}

	private void PaintDialog(){
		final int vgap = 10;
        m_image = new ImagePanel();
		Dimension dm = new Dimension(380, 380);
		m_image.setPreferredSize(dm);
		m_ParentPanel = new JPanel();
		m_SubPanel = new JPanel();
        m_OpenModePanel = new JPanel();
		m_OPModeGroup = new ButtonGroup();
		m_IMGProcGroup = new ButtonGroup();
        m_IMGProcPanel = new JPanel();

        m_CheckPAD = new JCheckBox();
		m_CheckPAD.setActionCommand(ACT_PAD);
		m_CheckPAD.addActionListener(this);

        m_btnBack = new JButton();
		m_btnBack.setActionCommand(ACT_BACK);
		m_btnBack.addActionListener(this);

		m_ParentPanel.setLayout(new GridLayout(1, 2));
		m_ParentPanel.add(m_image);
		m_SubPanel.setLayout(new BoxLayout(m_SubPanel, BoxLayout.Y_AXIS));

		m_OpenModePanel.setBorder(BorderFactory.createTitledBorder("Opening Mode"));
		m_OpenModePanel.setLayout(new GridLayout(OPModeAttr.length, 1));

		for (int i = 0; i < OPModeAttr.length; i++) {
			addRadioButton(m_OpenModePanel, m_OPModeGroup, i, OPModeAttr[i]);
		}

		m_SubPanel.add(m_OpenModePanel);
		m_SubPanel.add(Box.createVerticalStrut(vgap));
		m_SubPanel.add(Box.createVerticalStrut(vgap));

        m_IMGProcPanel.setBorder(BorderFactory.createTitledBorder("Image Processing (not all readers)"));
		m_IMGProcPanel.setLayout(new GridLayout(IMGProcAttr.length, 1));
		for (int i = 0; i < IMGProcAttr.length; i++) {
			addRadioButton(m_IMGProcPanel, m_IMGProcGroup, i, IMGProcAttr[i]);
		}

        m_SubPanel.add(m_IMGProcPanel);
		m_SubPanel.add(Box.createVerticalStrut(vgap));

		m_CheckPAD.setText("Spoof detection (not all readers)");
		if (m_bStreaming) {
			m_CheckPAD.setEnabled(false);
		}

        m_SubPanel.add(m_CheckPAD);
		m_SubPanel.add(Box.createVerticalStrut(100));

        m_btnBack.setText("Back");
		m_SubPanel.add(m_btnBack);

		m_ParentPanel.add(m_SubPanel);
		add(m_ParentPanel);

	}

    private void CheckPADActionPerformed() {
		if (m_reader == null) {
			return;
		}

		byte[] params = new byte[1];
		try {
	        if (m_CheckPAD.isSelected()) {
				params[0] = (byte) 1;
			}
			else
				params[0] = (byte) 0;
			
			m_reader.SetParameter(Reader.ParamId.DPFPDD_PARMID_PAD_ENABLE, params);
		}
		catch(UareUException e){ MessageBox.DpError("Set PAD parameter fail!", e);
			m_CheckPAD.setSelected(false); }
    }

	private Capture(Reader reader, boolean bStreaming){
		m_reader = reader;
		m_bStreaming = bStreaming;
		
		m_capture = new CaptureThread(m_reader, m_bStreaming, Fid.Format.ANSI_381_2004, Reader.ImageProcessing.IMG_PROC_DEFAULT);
		PaintDialog();
	}

	private void StartCaptureThread(){
		m_capture = new CaptureThread(m_reader, m_bStreaming, Fid.Format.ANSI_381_2004, Reader.ImageProcessing.valueOf(IMGProcProp[IMGProcIndex]));
		m_capture.start(this);
	}

	private void StopCaptureThread(){
		if(null != m_capture) m_capture.cancel();
	}
	
	private void WaitForCaptureThread(){
		if(null != m_capture) m_capture.join(1000);
	}

	private void RestartCaptureThread(boolean isOpeningMode){
		StopCaptureThread();
		WaitForCaptureThread();
		if(!isOpeningMode) {
			ImageProcessingChange = true;
			StartCaptureThread();
		} else
			ImageProcessingChange = false;
	}

	public void actionPerformed(ActionEvent e){
		String event = e.getActionCommand();
		if(event.equals(ACT_OPM_EXC) || event.equals(ACT_OPM_COOP)) {
			for(int i = 0; i < OPModeAttr.length; i++) {
				if(i != UareUSampleJava.OpeningModeFlag.ordinal() &&
					event.equals(OPModeAttr[i])) {
					UareUSampleJava.OpeningModeFlag = Reader.Priority.valueOf(OPModeProp[i]);
					MessageBox.Warning("Please restart Capture function!");
					RestartCaptureThread(true);
				}
			}
		}
		if(event.equals(ACT_IMGP_DEF) || event.equals(ACT_IMGP_PIV) ||
			event.equals(ACT_IMGP_ENH) || event.equals(ACT_IMGP_ENH2)) {
			for(int i = 0; i < IMGProcAttr.length; i++) {
				if(event.equals(IMGProcAttr[i])) {
					IMGProcIndex = i;
					RestartCaptureThread(false);
				}
			}
		}
		else if(e.getActionCommand().equals(ACT_PAD)){
			CheckPADActionPerformed();
		}
		else if(e.getActionCommand().equals(ACT_BACK)){
			//event from "back" button
			//cancel capture
			StopCaptureThread();
			ImageProcessingChange = false;
		}
		else if(e.getActionCommand().equals(CaptureThread.ACT_CAPTURE)){
			//event from capture thread
			CaptureThread.CaptureEvent evt = (CaptureThread.CaptureEvent)e;
			boolean bCanceled = false;
			
			if(null != evt.capture_result){
				boolean bGoodImage = false;
				if(null != evt.capture_result.image){
					if(m_bStreaming && (Reader.CaptureQuality.GOOD == evt.capture_result.quality || Reader.CaptureQuality.NO_FINGER == evt.capture_result.quality)) bGoodImage = true;
					if(!m_bStreaming && Reader.CaptureQuality.GOOD == evt.capture_result.quality) bGoodImage = true;
				}
				if(bGoodImage){
					//display image
					m_image.showImage(evt.capture_result.image);
				}
				else if(Reader.CaptureQuality.CANCELED == evt.capture_result.quality){
					//capture or streaming was canceled, just quit
					bCanceled = true;
				}
				else if(Reader.CaptureQuality.FAKE_FINGER== evt.capture_result.quality){
					MessageBox.Warning("Fake finger detected (code " + evt.capture_result.quality.ordinal() + ")");
				}
				else{
					//bad quality
					MessageBox.Warning("Not a finger detected (code " + evt.capture_result.quality.ordinal() + ")");
				}
			}
			else if(null != evt.exception){
				//exception during capture
				MessageBox.DpError("Capture",  evt.exception);
				bCanceled = true;
			}
			else if(null != evt.reader_status){
				MessageBox.BadStatus(evt.reader_status);
				bCanceled = true;
			}
			
			if(!bCanceled){
				if(!m_bStreaming){
					//restart capture thread
					WaitForCaptureThread();
					StartCaptureThread();
				}
			}
			else{
				if (!ImageProcessingChange) {
					//destroy dialog
					m_dlgParent.setVisible(false);
				}
				else
					ImageProcessingChange = false;
			}
		}
	}

	private void doModal(JDialog dlgParent){
		//open reader
		try{
			m_reader.Open(UareUSampleJava.OpeningModeFlag);
		}
		catch(UareUException e){ MessageBox.DpError("Reader.Open()", e); }
		
		boolean bOk = true;
		if(m_bStreaming){
			//check if streaming supported
			Reader.Capabilities rc = m_reader.GetCapabilities();
			if(null != rc && !rc.can_stream){
				MessageBox.Warning("This reader does not support streaming");
				bOk = false;
			}
		}
		
		if(bOk){
			CheckPADActionPerformed();
			//start capture thread
			StartCaptureThread();
	
			//bring up modal dialog
			m_dlgParent = dlgParent;
			m_dlgParent.setContentPane(this);
			m_dlgParent.pack();
			m_dlgParent.setLocationRelativeTo(null);
			m_dlgParent.toFront();
			m_dlgParent.setVisible(true);
			m_dlgParent.dispose();
			
			//cancel capture
			StopCaptureThread();
			
			//wait for capture thread to finish
			WaitForCaptureThread();
		}
		
		//close reader
		try{
			m_reader.Close();
		}
		catch(UareUException e){ MessageBox.DpError("Reader.Close()", e); }
	}
	
	public static void Run(JDialog parent, Reader reader, boolean bStreaming){
    	JDialog dlg = new JDialog(parent, "Put your finger on the reader", true);
    	Capture capture = new Capture(reader, bStreaming);
    	capture.doModal(dlg);
	}
}
